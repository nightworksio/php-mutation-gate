//! A pack of proofs: its class, its base, and its entries by key, each line as
//! it was written. A pack is read within the limits the gate holds a ledger
//! to, and written within them, the newest entries kept where not all fit.

use std::collections::BTreeMap;
use std::io::{Read, Write};

use flate2::Compression;
use flate2::read::GzDecoder;
use flate2::write::GzEncoder;
use outbreak_map::json::{Cursor, Number, Refused};

use crate::Failure;
use crate::request::Limits;

/// The contract a pack is written in, as the gate's `CachePack::FORMAT` says.
pub const FORMAT: u64 = 1;

/// What the first line calls a pack of proofs.
const PROOFS: &str = "proofs";

/// A pack of one class of writer at one base.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Pack {
    /// The class of writer: `trusted` or `branch`.
    pub class: String,
    /// The base every proof in it is at.
    pub base: String,
    /// Each entry's line, by its key, with the order it was added in.
    entries: BTreeMap<String, (u64, String)>,
    /// How many entries were ever added, which orders them.
    added: u64,
}

impl Pack {
    /// An empty pack of this class at this base.
    #[must_use]
    pub fn empty(class: &str, base: &str) -> Self {
        Self {
            class: class.to_owned(),
            base: base.to_owned(),
            entries: BTreeMap::new(),
            added: 0,
        }
    }

    /// How many entries it holds.
    #[must_use]
    pub fn len(&self) -> usize {
        self.entries.len()
    }

    /// Whether it holds no entry.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }

    /// This pack, with each entry of a newer one of its class and base over its own under the same key.
    #[must_use]
    pub fn merged(mut self, newer: Self) -> Self {
        let mut ordered: Vec<(u64, String, String)> = newer
            .entries
            .into_iter()
            .map(|(key, (order, line))| (order, key, line))
            .collect();
        ordered.sort_by_key(|(order, _, _)| *order);

        for (_, key, line) in ordered {
            self.added += 1;
            self.entries.insert(key, (self.added, line));
        }

        self
    }

    /// The pack's text, the header first, then as many entries as fit the limit, newest first.
    fn text(&self, most: u64) -> String {
        let header = header(&self.class, &self.base);
        let mut size = header.len() as u64 + 1;
        let mut ordered: Vec<&(u64, String)> = self.entries.values().collect();
        ordered.sort_by_key(|(order, _)| std::cmp::Reverse(*order));
        let mut kept = Vec::new();

        for (_, line) in ordered {
            let more = line.len() as u64 + 1;

            if size + more <= most {
                size += more;
                kept.push(line.as_str());
            }
        }

        kept.reverse();
        let mut text = header;
        text.push('\n');

        for line in kept {
            text.push_str(line);
            text.push('\n');
        }

        text
    }

    /// The pack's gzipped bytes, within the limits.
    ///
    /// # Errors
    ///
    /// Fails where the pack cannot be compressed within the packed limit.
    pub fn bytes(&self, limits: Limits) -> Result<Vec<u8>, Failure> {
        let mut most = limits.unpacked;

        loop {
            let bytes = gzipped(self.text(most).as_bytes());

            if bytes.len() as u64 <= limits.packed {
                return Ok(bytes);
            }

            if most == 0 {
                return Err(Failure::Refused(
                    "the pack's header alone is past the packed limit".to_owned(),
                ));
            }

            most /= 2;
        }
    }
}

/// A text gzipped, in memory, which no write fails.
fn gzipped(text: &[u8]) -> Vec<u8> {
    let mut encoder = GzEncoder::new(Vec::new(), Compression::new(9));

    encoder
        .write_all(text)
        .and_then(|()| encoder.finish())
        .unwrap_or_default()
}

/// The first line of a pack of this class at this base.
#[must_use]
pub fn header(class: &str, base: &str) -> String {
    format!(
        r#"{{"outbreak":{FORMAT},"pack":"{PROOFS}","class":"{}","base":"{}"}}"#,
        escaped(class),
        escaped(base)
    )
}

fn escaped(text: &str) -> String {
    text.chars()
        .filter(|character| character.is_ascii_alphanumeric() || matches!(character, '-' | '_'))
        .collect()
}

/// The pack gzipped bytes hold, within the limits; refused where it is past them, or not a pack.
///
/// # Errors
///
/// Refuses bytes past a limit, that are no whole gzip stream, or that hold no pack.
pub fn read(bytes: &[u8], limits: Limits) -> Result<Pack, Failure> {
    if bytes.len() as u64 > limits.packed {
        return Err(Failure::Refused(
            "the pack is past the packed limit".to_owned(),
        ));
    }

    let mut text = Vec::new();
    GzDecoder::new(bytes)
        .take(limits.unpacked.saturating_add(1))
        .read_to_end(&mut text)
        .map_err(|error| Failure::Refused(format!("the pack is no whole gzip stream: {error}")))?;

    if text.len() as u64 > limits.unpacked {
        return Err(Failure::Refused(
            "the pack is past the unpacked limit".to_owned(),
        ));
    }

    parse(&text).map_err(|refused| {
        Failure::Refused(format!(
            "the pack: expected {} at byte {}",
            refused.expected, refused.at
        ))
    })
}

/// The pack a text holds.
///
/// # Errors
///
/// Refuses a text whose first line is no pack's header, or a line that is no entry.
pub fn parse(text: &[u8]) -> Result<Pack, Refused> {
    let mut lines = text.split(|byte| *byte == b'\n');
    let first = lines.next().unwrap_or_default();
    let (class, base) = read_header(first)?;
    let mut pack = Pack::empty(&class, &base);
    let mut at = first.len() + 1;

    for line in lines {
        if !line.is_empty() {
            let key = read_entry(line).map_err(|refused| Refused {
                at: at + refused.at,
                expected: refused.expected,
            })?;
            pack.added += 1;
            pack.entries.insert(
                key,
                (pack.added, String::from_utf8_lossy(line).into_owned()),
            );
        }

        at += line.len() + 1;
    }

    Ok(pack)
}

fn read_header(line: &[u8]) -> Result<(String, String), Refused> {
    let mut json = Cursor::over(line)?;
    let mut members = json.object()?;
    let (mut format, mut pack, mut class, mut base) = (None, None, None, None);

    while let Some(key) = members.next(&mut json)? {
        match key.text.as_str() {
            "outbreak" => format = Some(json.number()?),
            "pack" => pack = Some(json.string()?),
            "class" => class = Some(json.string()?),
            "base" => base = Some(json.string()?),
            _ => json.skip()?,
        }
    }

    json.end()?;

    match (format, pack, class, base) {
        (Some(Number::Whole(FORMAT)), Some(pack), Some(class), Some(base))
            if pack == PROOFS && (class == "trusted" || class == "branch") =>
        {
            Ok((class, base))
        }
        _ => Err(Refused {
            at: 0,
            expected: "a pack of proofs in contract 1",
        }),
    }
}

fn read_entry(line: &[u8]) -> Result<String, Refused> {
    let mut json = Cursor::over(line)?;
    let mut members = json.object()?;
    let (mut key, mut ledger) = (None, false);

    while let Some(name) = members.next(&mut json)? {
        match name.text.as_str() {
            "key" => key = Some(json.string()?),
            "ledger" => {
                json.string()?;
                ledger = true;
            }
            _ => {
                return Err(Refused {
                    at: name.raw.start,
                    expected: "a key and a ledger",
                });
            }
        }
    }

    json.end()?;

    match key {
        Some(key) if ledger && !key.is_empty() => Ok(key),
        _ => Err(Refused {
            at: 0,
            expected: "an entry: a key and a ledger",
        }),
    }
}

#[cfg(test)]
mod tests {
    use std::fmt::Write as _;

    use super::{Pack, header, parse, read};
    use crate::Failure;
    use crate::request::Limits;
    use crate::tests::LIMITS;

    fn pack(entries: &[(&str, &str)]) -> Vec<u8> {
        let mut text = header("trusted", "base1");
        text.push('\n');

        for (key, ledger) in entries {
            let _ = writeln!(text, r#"{{"key":"{key}","ledger":"{ledger}"}}"#);
        }

        text.into_bytes()
    }

    fn refused(failed: Result<Pack, Failure>) -> String {
        failed.unwrap_err().to_string()
    }

    #[test]
    fn keeps_the_newer_entry_under_a_key() {
        let older = parse(&pack(&[("a", "old"), ("b", "kept")])).unwrap();
        let newer = parse(&pack(&[("a", "new")])).unwrap();
        let merged = older.merged(newer);

        assert_eq!(merged.len(), 2);
        assert!(!merged.is_empty());
        assert!(Pack::empty("trusted", "base1").is_empty());
        assert!(merged.text(u64::MAX).contains(r#""ledger":"new""#));
        assert!(!merged.text(u64::MAX).contains(r#""ledger":"old""#));
    }

    #[test]
    fn keeps_the_newest_entries_that_fit() {
        let read = parse(&pack(&[("a", "first"), ("b", "second")])).unwrap();
        let kept = read.text(header("trusted", "base1").len() as u64 + 40);

        assert!(kept.contains("second") && !kept.contains("first"));
    }

    #[test]
    fn writes_what_it_reads() {
        let bytes = parse(&pack(&[("a", "x")])).unwrap().bytes(LIMITS).unwrap();
        let again = read(&bytes, LIMITS).unwrap();

        assert_eq!(again.len(), 1);
        assert_eq!(again.base, "base1");
    }

    #[test]
    fn drops_the_oldest_entries_to_fit_the_packed_limit() {
        let entries: Vec<(String, String)> = (0..64)
            .map(|index| (format!("k{index}"), format!("{:064x}", index * 7_919)))
            .collect();
        let borrowed: Vec<(&str, &str)> = entries
            .iter()
            .map(|(key, ledger)| (key.as_str(), ledger.as_str()))
            .collect();
        let full = parse(&pack(&borrowed)).unwrap();
        let whole = full.bytes(LIMITS).unwrap();
        let limits = Limits {
            packed: whole.len() as u64 / 2,
            unpacked: LIMITS.unpacked,
        };
        let halved = read(&full.bytes(limits).unwrap(), limits).unwrap();

        assert!(halved.len() < full.len());
        assert!(halved.text(u64::MAX).contains(r#""key":"k63""#));
        assert_eq!(
            full.bytes(Limits {
                packed: 1,
                unpacked: 1
            }),
            Err(Failure::Refused(
                "the pack's header alone is past the packed limit".to_owned()
            ))
        );
    }

    #[test]
    fn refuses_bytes_past_a_limit_or_no_whole_pack() {
        let bytes = Pack::empty("trusted", "base1").bytes(LIMITS).unwrap();

        assert!(
            refused(read(
                &bytes,
                Limits {
                    packed: 1,
                    unpacked: LIMITS.unpacked
                }
            ))
            .contains("packed limit")
        );
        assert!(
            refused(read(
                &bytes,
                Limits {
                    packed: LIMITS.packed,
                    unpacked: 1
                }
            ))
            .contains("unpacked limit")
        );
        assert!(refused(read(b"plain", LIMITS)).contains("gzip"));

        let unpacked = Pack {
            class: "anyone".to_owned(),
            ..Pack::empty("trusted", "base1")
        }
        .bytes(LIMITS)
        .unwrap();

        assert_eq!(
            refused(read(&unpacked, LIMITS)),
            "the pack: expected a pack of proofs in contract 1 at byte 0"
        );
    }

    #[test]
    fn refuses_another_contract() {
        assert!(parse(br#"{"outbreak":2,"pack":"proofs","class":"trusted","base":"b"}"#).is_err());
    }

    #[test]
    fn skips_a_header_s_other_keys_and_refuses_a_line_that_is_no_entry() {
        let mut text =
            br#"{"outbreak":1,"pack":"proofs","class":"branch","base":"b","made":"x"}"#.to_vec();

        assert_eq!(parse(&text).unwrap().class, "branch");

        text.extend_from_slice(b"\n{\"key\":\"a\",\"ledger\":\"l\",\"more\":1}");
        let at = parse(&text).unwrap_err().at;

        assert_eq!(text.get(at..at + 6), Some(&b"\"more\""[..]));
        assert!(parse(&pack(&[("", "l")])).is_err());
        assert!(parse(b"{\"outbreak\":1,\"pack\":\"proofs\",\"class\":\"trusted\",\"base\":\"b\"}\n{\"key\":\"a\"}").is_err());
    }
}
