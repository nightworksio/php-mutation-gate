//! What the daemon holds in memory (ADR-0133, decision 1): the proofs the
//! gate kept and has not forgotten, by key, and what each file of the static
//! graph declares and names, by its path and the digest of its bytes, so a
//! file is read for its names again only where it changed (ADR-0132,
//! decision 6).

use std::collections::BTreeMap;

use outbreak_graph::names::{self, FileNames};
use sha2::{Digest as _, Sha256};

use crate::journal::{Kind, Record};

/// The daemon's state.
#[derive(Debug, Default)]
pub struct State {
    proofs: BTreeMap<String, Vec<u8>>,
    names: BTreeMap<String, ([u8; 32], FileNames)>,
}

impl State {
    /// The state these records leave, each later record of a key in place of an earlier one.
    #[must_use]
    pub fn of(records: impl IntoIterator<Item = Record>) -> Self {
        let mut state = Self::default();

        for record in records {
            state.apply(record);
        }

        state
    }

    /// This state, with a record in place of any it held under its key, or none under the key a forgetting names.
    pub fn apply(&mut self, record: Record) {
        match record.kind {
            Kind::Proof => {
                self.proofs.insert(record.key, record.value);
            }
            Kind::Forgotten => {
                self.proofs.remove(&record.key);
            }
        }
    }

    /// Every record the state holds, as a snapshot keeps them.
    #[must_use]
    pub fn records(&self) -> Vec<Record> {
        self.proofs
            .iter()
            .map(|(key, value)| Record {
                kind: Kind::Proof,
                key: key.clone(),
                value: value.clone(),
            })
            .collect()
    }

    /// The proof held under a key.
    #[must_use]
    pub fn proof(&self, key: &str) -> Option<&[u8]> {
        self.proofs.get(key).map(Vec::as_slice)
    }

    /// What a file's bytes declare and name: as read before where its bytes are the same, and read now otherwise.
    pub fn names(&mut self, path: &str, bytes: &[u8]) -> FileNames {
        let digest: [u8; 32] = Sha256::digest(bytes).into();

        if let Some((held, names)) = self.names.get(path)
            && *held == digest
        {
            return names.clone();
        }

        let read = names::read(bytes);
        self.names.insert(path.to_owned(), (digest, read.clone()));

        read
    }
}

#[cfg(test)]
mod tests {
    use super::State;
    use crate::journal::{Kind, Record};

    #[test]
    fn keeps_the_latest_proof_of_each_key() {
        let record = |value: &str| Record {
            kind: Kind::Proof,
            key: "k".to_owned(),
            value: value.as_bytes().to_vec(),
        };
        let state = State::of([record("old"), record("new")]);

        assert_eq!(state.proof("k"), Some(&b"new"[..]));
        assert_eq!(state.proof("other"), None);
        assert_eq!(state.records(), vec![record("new")]);
    }

    #[test]
    fn forgets_a_proof_until_it_is_kept_again() {
        let kept = Record {
            kind: Kind::Proof,
            key: "k".to_owned(),
            value: b"proof".to_vec(),
        };
        let forgotten = Record {
            kind: Kind::Forgotten,
            key: "k".to_owned(),
            value: Vec::new(),
        };

        assert_eq!(
            State::of([kept.clone(), forgotten.clone()]).proof("k"),
            None
        );
        assert_eq!(
            State::of([kept.clone(), forgotten.clone()]).records(),
            Vec::new()
        );
        assert_eq!(State::of([forgotten, kept]).proof("k"), Some(&b"proof"[..]));
    }

    #[test]
    fn reads_a_file_again_only_where_its_bytes_changed() {
        let mut state = State::default();
        let first = state.names("a.php", b"<?php class A {}");
        let same = state.names("a.php", b"<?php class A {}");
        let changed = state.names("a.php", b"<?php class B {}");

        assert_eq!(first, same);
        assert!(changed.declares.contains("b"));
    }
}
