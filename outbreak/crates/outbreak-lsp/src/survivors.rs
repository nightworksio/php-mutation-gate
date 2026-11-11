//! The survivors `mutation-gate watch --output=events` tells of (ADR-0146 decision 8), held by
//! unit and shown as one diagnostic per mutant on its site's line.

use std::collections::BTreeMap;
use std::fmt::Write;

use outbreak_graph::quoted;
use outbreak_map::json::Cursor;

use crate::message;

/// How JSON writes no value.
const NULL: &[u8] = b"null";

/// How many lowercase hex characters a mutant's id is.
const ID_LENGTH: usize = 12;

/// What the editor shows as each diagnostic's source.
pub const SOURCE: &str = "mutation-gate";

/// What a survivor's diagnostic says.
const SURVIVED: &str = "Mutant {id} survived: no test fails with it in place.";

/// What a survivor of a quick round's diagnostic says besides.
const QUICK: &str = " A quick round judged it, which is no verdict.";

/// What a survivor judged against an earlier version of its unit's file says besides.
const EARLIER: &str = " It was judged against an earlier version of this file.";

/// What an event says of a mutant.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Status {
    /// A test failed with it in place.
    Killed,
    /// No test failed with it in place.
    Survived,
    /// Its tests ran out of time with it in place.
    Timeout,
    /// It is not judged yet.
    Pending,
    /// It is proven to behave as the code does.
    Equivalent,
}

impl Status {
    fn of(text: &str) -> Option<Self> {
        match text {
            "killed" => Some(Self::Killed),
            "survived" => Some(Self::Survived),
            "timeout" => Some(Self::Timeout),
            "pending" => Some(Self::Pending),
            "equivalent" => Some(Self::Equivalent),
            _ => None,
        }
    }
}

/// How the editor shows a survivor, by the severity the protocol numbers it with.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(u8)]
pub enum Severity {
    /// As an error.
    Error = 1,
    /// As a warning.
    Warning = 2,
    /// As information.
    Information = 3,
    /// As a hint.
    Hint = 4,
}

impl Severity {
    /// The severity a name says: `error`, `warning`, `information` or `hint`.
    #[must_use]
    pub fn named(name: &str) -> Option<Self> {
        match name {
            "error" => Some(Self::Error),
            "warning" => Some(Self::Warning),
            "information" => Some(Self::Information),
            "hint" => Some(Self::Hint),
            _ => None,
        }
    }
}

/// One line of `watch --output=events`: a judged mutant.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Event {
    /// The mutant's id.
    pub mutant: String,
    /// The line its site starts on, counted from one.
    pub line: u64,
    /// What was judged.
    pub status: Status,
    /// Whether a quick round judged it.
    pub quick: bool,
    /// The file its unit is, relative to the project's root.
    pub unit: String,
    /// The digest of the unit's file it was judged against, where the plan took one.
    pub digest: Option<String>,
}

impl Event {
    /// The event a line says; none of a line that is no event.
    #[must_use]
    pub fn read(line: &[u8]) -> Option<Self> {
        let mut cursor = Cursor::over(line).ok()?;
        let mut members = cursor.object().ok()?;
        let (mut mutant, mut site, mut status, mut quick, mut unit, mut digest) =
            (None, None, None, None, None, None);

        while let Some(key) = members.next(&mut cursor).ok()? {
            let value = cursor.spanned().ok().map(|span| cursor.bytes(span))?;

            match key.text.as_str() {
                "mutant" => mutant = message::string(value).filter(|id| is_mutant(id)),
                "site" => site = message::string(value),
                "status" => status = message::string(value).as_deref().and_then(Status::of),
                "quick" => quick = message::boolean(value),
                "unit" => unit = message::string(value),
                "digest" => {
                    digest = Some(if value == NULL {
                        None
                    } else {
                        Some(message::string(value)?)
                    })
                }
                _ => {}
            }
        }

        cursor.end().ok()?;

        let line = site?
            .rsplit_once(':')
            .and_then(|(_, line)| line.parse::<u64>().ok())
            .filter(|line| *line > 0)?;

        Some(Self {
            mutant: mutant?,
            line,
            status: status?,
            quick: quick?,
            unit: unit?,
            digest: digest?,
        })
    }
}

/// Whether a text is a mutant's id as the gate writes it in full.
#[must_use]
pub fn is_mutant(text: &str) -> bool {
    text.len() == ID_LENGTH
        && text
            .bytes()
            .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
}

/// The survivors of every unit an event told of.
#[derive(Debug, Default)]
pub struct Survivors {
    units: BTreeMap<String, Unit>,
}

#[derive(Debug, Default)]
struct Unit {
    digest: Option<String>,
    mutants: BTreeMap<String, Survivor>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct Survivor {
    line: u64,
    quick: bool,
    digest: Option<String>,
}

impl Survivors {
    /// Takes an event in, and answers the unit whose diagnostics it moved, where it moved any.
    ///
    /// A survivor stands until a later event of the same mutant says it was killed, timed out
    /// or is equivalent; a pending event leaves it standing; an event of a later digest of its
    /// unit leaves it standing too, said to be judged against an earlier version of the file.
    pub fn take(&mut self, event: Event) -> Option<String> {
        let unit = self.units.entry(event.unit.clone()).or_default();
        let mut moved = false;

        if event.digest.is_some() && unit.digest != event.digest {
            unit.digest.clone_from(&event.digest);
            moved = !unit.mutants.is_empty();
        }

        match event.status {
            Status::Survived => {
                let survivor = Survivor {
                    line: event.line,
                    quick: event.quick,
                    digest: event.digest,
                };
                moved |= unit.mutants.insert(event.mutant, survivor.clone()) != Some(survivor);
            }
            Status::Killed | Status::Timeout | Status::Equivalent => {
                moved |= unit.mutants.remove(&event.mutant).is_some();
            }
            Status::Pending => {}
        }

        moved.then_some(event.unit)
    }

    /// The diagnostics of a unit, as the JSON array `textDocument/publishDiagnostics` takes, each
    /// covering the whole line its site starts on.
    #[must_use]
    pub fn diagnostics(&self, unit: &str, severity: Severity) -> String {
        let mut written = String::from("[");

        if let Some(unit) = self.units.get(unit) {
            for (at, (mutant, survivor)) in unit.mutants.iter().enumerate() {
                let earlier = survivor.digest.is_some()
                    && unit.digest.is_some()
                    && survivor.digest != unit.digest;
                let said = format!(
                    "{}{}{}",
                    SURVIVED.replace("{id}", mutant),
                    if survivor.quick { QUICK } else { "" },
                    if earlier { EARLIER } else { "" }
                );
                let _ = write!(
                    written,
                    r#"{}{{"range":{{"start":{{"line":{},"character":0}},"end":{{"line":{},"character":0}}}},"severity":{},"code":{},"source":{},"message":{}}}"#,
                    if at == 0 { "" } else { "," },
                    survivor.line.saturating_sub(1),
                    survivor.line,
                    severity as u8,
                    quoted(mutant),
                    quoted(SOURCE),
                    quoted(&said)
                );
            }
        }

        written.push(']');
        written
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn event(mutant: &str, status: &str, line: u64, digest: &str) -> Event {
        Event::read(
            format!(
                r#"{{"mutant":"{mutant}","site":"src/A.php:{line}","status":"{status}","by":null,"quick":true,"unit":"src/A.php","digest":"{digest}"}}"#
            )
            .as_bytes(),
        )
        .unwrap()
    }

    #[test]
    fn reads_an_event_and_no_other_line() {
        let read = Event::read(
            br#"{"mutant":"0123456789ab","site":"src/A.php:7","status":"survived","by":null,"quick":false,"unit":"src/A.php","digest":null}"#,
        )
        .unwrap();

        assert_eq!(read.line, 7);
        assert_eq!(read.status, Status::Survived);
        assert_eq!(read.digest, None);
        assert!(!read.quick);

        for line in [
            &b"Watching src for changes"[..],
            br#"{"mutant":"0123456789ab","site":"src/A.php:0","status":"survived","quick":false,"unit":"u","digest":null}"#,
            br#"{"mutant":"-rf","site":"src/A.php:1","status":"survived","quick":false,"unit":"u","digest":null}"#,
            br#"{"mutant":"0123456789ab","site":"src/A.php:1","status":"verifying","quick":false,"unit":"u","digest":null}"#,
            br#"{"mutant":"0123456789ab","site":"src/A.php:1","status":"survived","quick":false,"unit":"u"}"#,
        ] {
            assert_eq!(Event::read(line), None, "{}", String::from_utf8_lossy(line));
        }
    }

    #[test]
    fn holds_a_survivor_until_a_later_event_judges_it_otherwise() {
        let mut survivors = Survivors::default();

        assert_eq!(
            survivors
                .take(event("0123456789ab", "survived", 3, "d1"))
                .as_deref(),
            Some("src/A.php")
        );
        assert_eq!(
            survivors.take(event("0123456789ab", "survived", 3, "d1")),
            None
        );
        assert_eq!(
            survivors.take(event("0123456789ab", "pending", 3, "d1")),
            None
        );
        assert!(
            survivors
                .diagnostics("src/A.php", Severity::Warning)
                .contains(r#""line":2,"character":0"#)
        );
        assert_eq!(
            survivors
                .take(event("0123456789ab", "killed", 3, "d1"))
                .as_deref(),
            Some("src/A.php")
        );
        assert_eq!(survivors.diagnostics("src/A.php", Severity::Warning), "[]");
        assert_eq!(survivors.diagnostics("src/B.php", Severity::Warning), "[]");
    }

    #[test]
    fn says_a_survivor_of_an_earlier_digest_was_judged_against_an_earlier_file() {
        let mut survivors = Survivors::default();
        survivors.take(event("0123456789ab", "survived", 3, "d1"));

        assert!(
            !survivors
                .diagnostics("src/A.php", Severity::Hint)
                .contains(EARLIER.trim())
        );
        assert_eq!(
            survivors
                .take(event("ba9876543210", "killed", 9, "d2"))
                .as_deref(),
            Some("src/A.php")
        );

        let shown = survivors.diagnostics("src/A.php", Severity::Hint);

        assert!(shown.contains(EARLIER.trim()));
        assert!(shown.contains(QUICK.trim()));
        assert!(shown.contains(r#""severity":4,"code":"0123456789ab","source":"mutation-gate""#));
    }
}
