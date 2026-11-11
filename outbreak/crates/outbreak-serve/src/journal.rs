//! How the daemon's state survives it (ADR-0133, decision 3): records, each
//! length-prefixed and checksummed, appended to a journal, and a snapshot of
//! every record the state holds behind a versioned header with the checksum
//! of its body.
//!
//! A record is `len: u32 LE`, the first eight bytes of the SHA-256 of its
//! payload, and the payload: its kind's byte, `key_len: u32 LE`, the key and
//! the value. A journal is read up to its first record that is cut short or
//! whose checksum fails; a snapshot is read whole, or not at all.

use sha2::{Digest as _, Sha256};

/// What a snapshot's file starts with: its name and its version.
pub const SNAPSHOT: &[u8; 8] = b"OBSTATE1";

/// The longest payload a record may hold.
pub const LONGEST: usize = 1 << 26;

const CHECK: usize = 8;

const LENGTH: usize = 4;

/// What a record holds.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Kind {
    /// A proof, by its key.
    Proof,
    /// A proof no longer held, by its key.
    Forgotten,
}

impl Kind {
    const fn byte(self) -> u8 {
        match self {
            Self::Proof => 1,
            Self::Forgotten => 2,
        }
    }

    const fn of(byte: u8) -> Option<Self> {
        match byte {
            1 => Some(Self::Proof),
            2 => Some(Self::Forgotten),
            _ => None,
        }
    }
}

/// One record: what it holds, under which key, and its value.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Record {
    /// What the record holds.
    pub kind: Kind,
    /// Its key.
    pub key: String,
    /// Its value.
    pub value: Vec<u8>,
}

/// A record's bytes as a journal and a snapshot hold them; none where its payload is longer than `LONGEST`.
#[must_use]
pub fn written(record: &Record) -> Option<Vec<u8>> {
    let key = u32::try_from(record.key.len()).ok()?;
    let mut payload = Vec::with_capacity(1 + LENGTH + record.key.len() + record.value.len());
    payload.push(record.kind.byte());
    payload.extend_from_slice(&key.to_le_bytes());
    payload.extend_from_slice(record.key.as_bytes());
    payload.extend_from_slice(&record.value);

    if payload.len() > LONGEST {
        return None;
    }

    let length = u32::try_from(payload.len()).ok()?;
    let mut bytes = Vec::with_capacity(LENGTH + CHECK + payload.len());
    bytes.extend_from_slice(&length.to_le_bytes());
    bytes.extend_from_slice(&check(&payload));
    bytes.extend_from_slice(&payload);

    Some(bytes)
}

/// Every record of a journal, up to the first that is cut short, fails its checksum or cannot be read.
#[must_use]
pub fn journal(bytes: &[u8]) -> Vec<Record> {
    let mut records = Vec::new();
    let mut at = 0;

    while let Some((record, next)) = record_at(bytes, at) {
        records.push(record);
        at = next;
    }

    records
}

/// A snapshot's bytes: the header, the checksum of the body, and the body, every record in turn.
#[must_use]
pub fn snapshot<'a>(records: impl IntoIterator<Item = &'a Record>) -> Vec<u8> {
    let body: Vec<u8> = records.into_iter().filter_map(written).flatten().collect();
    let mut bytes = Vec::with_capacity(SNAPSHOT.len() + 32 + body.len());
    bytes.extend_from_slice(SNAPSHOT);
    bytes.extend_from_slice(&Sha256::digest(&body));
    bytes.extend_from_slice(&body);

    bytes
}

/// Every record of a snapshot; none where its header, its checksum or any record of it cannot be read.
#[must_use]
pub fn snapshot_read(bytes: &[u8]) -> Option<Vec<Record>> {
    let body = bytes.strip_prefix(SNAPSHOT.as_slice())?;
    let (sum, body) = body.split_at_checked(32)?;

    if Sha256::digest(body).as_slice() != sum {
        return None;
    }

    let mut records = Vec::new();
    let mut at = 0;

    while at < body.len() {
        let (record, next) = record_at(body, at)?;
        records.push(record);
        at = next;
    }

    Some(records)
}

fn record_at(bytes: &[u8], at: usize) -> Option<(Record, usize)> {
    let length = u32::from_le_bytes(bytes.get(at..at + LENGTH)?.try_into().ok()?);
    let length = usize::try_from(length)
        .ok()
        .filter(|length| *length <= LONGEST)?;
    let sum = bytes.get(at + LENGTH..at + LENGTH + CHECK)?;
    let start = at + LENGTH + CHECK;
    let payload = bytes.get(start..start.checked_add(length)?)?;

    if check(payload).as_slice() != sum {
        return None;
    }

    let kind = Kind::of(*payload.first()?)?;
    let key_length = u32::from_le_bytes(payload.get(1..=LENGTH)?.try_into().ok()?);
    let key_end = (1 + LENGTH).checked_add(usize::try_from(key_length).ok()?)?;
    let key = String::from_utf8(payload.get(1 + LENGTH..key_end)?.to_vec()).ok()?;
    let value = payload.get(key_end..)?.to_vec();

    Some((Record { kind, key, value }, start + length))
}

fn check(payload: &[u8]) -> [u8; CHECK] {
    let mut sum = [0; CHECK];
    sum.copy_from_slice(Sha256::digest(payload).get(..CHECK).unwrap_or_default());
    sum
}

#[cfg(test)]
mod tests {
    use super::{Kind, Record, check, journal, snapshot, snapshot_read, written};

    fn proof(key: &str, value: &str) -> Record {
        Record {
            kind: Kind::Proof,
            key: key.to_owned(),
            value: value.as_bytes().to_vec(),
        }
    }

    #[test]
    fn reads_a_journal_up_to_its_first_broken_record() {
        let mut bytes: Vec<u8> = [proof("a", "1"), proof("b", "2")]
            .iter()
            .filter_map(written)
            .flatten()
            .collect();
        let whole = journal(&bytes);
        let last = bytes.len() - 1;
        bytes[last] ^= 1;

        assert_eq!(whole, vec![proof("a", "1"), proof("b", "2")]);
        assert_eq!(journal(&bytes), vec![proof("a", "1")]);
        assert_eq!(journal(&bytes[..3]), vec![]);
    }

    #[test]
    fn reads_a_snapshot_whole_or_not_at_all() {
        let records = [proof("a", "1"), proof("ключ", "")];
        let mut bytes = snapshot(&records);

        assert_eq!(snapshot_read(&bytes), Some(records.to_vec()));
        assert_eq!(snapshot_read(&snapshot(&[])), Some(vec![]));

        let last = bytes.len() - 1;
        bytes[last] ^= 1;

        assert_eq!(snapshot_read(&bytes), None);
        assert_eq!(snapshot_read(b"OBSTATE0"), None);
        assert_eq!(snapshot_read(b"OBSTATE1short"), None);
    }

    #[test]
    fn refuses_a_record_of_an_unknown_kind_or_a_key_it_cannot_read() {
        for payload in [
            vec![9, 0, 0, 0, 0],
            vec![1, 1, 0, 0, 0, 0xff],
            vec![1, 9, 0, 0, 0],
        ] {
            let mut bytes = u32::try_from(payload.len()).unwrap().to_le_bytes().to_vec();
            bytes.extend_from_slice(&check(&payload));
            bytes.extend_from_slice(&payload);

            assert_eq!(journal(&bytes), vec![]);
        }
    }
}
