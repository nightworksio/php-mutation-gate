//! The packs of proofs `outbreak cache` keeps by their keys (ADR-0131): read
//! within the gate's limits, merged by key, and moved between the gate's
//! directory and their store, the Actions cache or an OCI registry.
//!
//! A pack is a gzipped text of lines. The first names the pack:
//! `{"outbreak":1,"pack":"proofs","class":<class>,"base":<base>}`. Every other
//! line is one entry, `{"key":<key>,"ledger":<text>}`, whose ledger it never
//! reads: a key is the whole question, so two entries under one key answer it
//! alike, and the newer is kept.

pub mod actions;
pub mod oci;
pub mod pack;
pub mod request;

use std::fmt;
use std::fs;
use std::path::Path;

use crate::pack::Pack;
use crate::request::Request;

/// Why a command of the cache could not run.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Failure {
    /// The request or a pack is not one the cache reads.
    Refused(String),
    /// A file, a stream or the store could not be read or written.
    Io(String),
}

impl fmt::Display for Failure {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Refused(why) | Self::Io(why) => formatter.write_str(why),
        }
    }
}

/// Every pack the request names pulled into its path from its store: how many were.
///
/// # Errors
///
/// Fails where the request cannot be read; a pack the store does not hold is not pulled.
pub fn pull(text: &[u8]) -> Result<usize, Failure> {
    let request = request::read(text)?;

    match request.store {
        request::Store::Actions => actions::pull(&request),
        request::Store::Oci => oci::pull(&request),
    }
}

/// The entries at each path the request names merged into their class's pack in the store: how many packs were.
///
/// # Errors
///
/// Fails where the request, the entries or the store cannot be read, or the store cannot be written.
pub fn push(text: &[u8]) -> Result<usize, Failure> {
    let request = request::read(text)?;

    match request.store {
        request::Store::Actions => actions::push(&request),
        request::Store::Oci => oci::push(&request),
    }
}

/// The entries of a class a push reads at this path.
///
/// # Errors
///
/// Fails where they cannot be read, or are no pack of the class at the request's base.
fn entries(request: &Request, class: &str, path: &str) -> Result<Pack, Failure> {
    let bytes =
        fs::read(path).map_err(|error| Failure::Io(format!("cannot read {path}: {error}")))?;
    let newer = pack::read(&bytes, request.limits)?;

    if newer.class == class && newer.base == request.base {
        Ok(newer)
    } else {
        Err(Failure::Refused(format!(
            "{path} is no {class} pack at the request's base"
        )))
    }
}

/// Bytes written to a path, its directory made first.
///
/// # Errors
///
/// Fails where the directory cannot be made or the file cannot be written.
fn write(path: &str, bytes: &[u8]) -> Result<(), Failure> {
    if let Some(parent) = Path::new(path).parent() {
        fs::create_dir_all(parent)
            .map_err(|error| Failure::Io(format!("cannot make {}: {error}", parent.display())))?;
    }

    fs::write(path, bytes).map_err(|error| Failure::Io(format!("cannot write {path}: {error}")))
}

#[cfg(test)]
pub(crate) mod tests {
    use std::collections::BTreeMap;
    use std::path::PathBuf;

    use super::{Failure, entries, pull, push, write};
    use crate::pack::Pack;
    use crate::request::{Limits, Request, Store};

    /// The limits every test holds a pack to.
    pub(crate) const LIMITS: Limits = Limits {
        packed: 1_000_000,
        unpacked: 1_000_000,
    };

    /// A directory of this test's own, empty.
    pub(crate) fn scratch(name: &str) -> PathBuf {
        let directory =
            std::env::temp_dir().join(format!("outbreak-cache-{name}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&directory);
        std::fs::create_dir_all(&directory).unwrap();

        directory
    }

    /// A request of the trusted class at `base1`, its pack at this path.
    pub(crate) fn request(store: Store, path: &str) -> Request {
        Request {
            store,
            registry: None,
            base: "base1".to_owned(),
            newest: false,
            limits: LIMITS,
            packs: BTreeMap::from([("trusted".to_owned(), path.to_owned())]),
            restored: BTreeMap::new(),
        }
    }

    /// A pack's bytes of this class and base holding one entry under this key.
    pub(crate) fn packed(class: &str, base: &str, key: &str) -> Vec<u8> {
        let newer = crate::pack::parse(
            format!(
                "{}\n{{\"key\":\"{key}\",\"ledger\":\"l\"}}\n",
                crate::pack::header(class, base)
            )
            .as_bytes(),
        )
        .unwrap();

        Pack::empty(class, base)
            .merged(newer)
            .bytes(LIMITS)
            .unwrap()
    }

    #[test]
    fn says_why_it_failed() {
        assert_eq!(Failure::Refused("no".to_owned()).to_string(), "no");
        assert_eq!(Failure::Io("lost".to_owned()).to_string(), "lost");
    }

    #[test]
    fn reads_the_request_before_it_reaches_a_store() {
        assert!(matches!(pull(b"{}"), Err(Failure::Refused(_))));
        assert!(matches!(push(b"[]"), Err(Failure::Refused(_))));
    }

    #[test]
    fn moves_packs_under_the_store_the_request_names() {
        let directory = scratch("lib");
        let entries = directory.join("entries.pack");
        let restored = directory.join("restored");
        std::fs::write(&entries, packed("trusted", "base1", "a")).unwrap();
        let text = |store: &str, path: &PathBuf| {
            format!(
                r#"{{"store":"{store}","base":"base1","limits":{{"packed":{},"unpacked":{}}},"packs":{{"trusted":{path:?}}},"restored":{{"trusted":{restored:?}}}}}"#,
                LIMITS.packed, LIMITS.unpacked
            )
        };

        assert_eq!(push(text("actions", &entries).as_bytes()), Ok(1));
        assert_eq!(
            pull(text("actions", &directory.join("pulled.pack")).as_bytes()),
            Ok(1)
        );
        assert_eq!(
            pull(text("oci", &entries).as_bytes()),
            Err(Failure::Refused("the request names no registry".to_owned()))
        );
        assert_eq!(
            push(text("oci", &entries).as_bytes()),
            Err(Failure::Refused("the request names no registry".to_owned()))
        );
    }

    #[test]
    fn reads_only_entries_of_the_class_at_the_base() {
        let directory = scratch("entries");
        let path = directory.join("entries.pack");
        let named = path.to_string_lossy().into_owned();
        let asked = request(Store::Actions, &named);

        assert!(matches!(
            entries(&asked, "trusted", &named),
            Err(Failure::Io(_))
        ));

        std::fs::write(&path, packed("trusted", "base2", "a")).unwrap();

        assert_eq!(
            entries(&asked, "trusted", &named),
            Err(Failure::Refused(format!(
                "{named} is no trusted pack at the request's base"
            )))
        );

        std::fs::write(&path, packed("branch", "base1", "a")).unwrap();

        assert!(entries(&asked, "trusted", &named).is_err());

        std::fs::write(&path, packed("trusted", "base1", "a")).unwrap();

        assert_eq!(entries(&asked, "trusted", &named).unwrap().len(), 1);
    }

    #[test]
    fn writes_into_a_directory_it_makes_and_fails_where_it_cannot() {
        let directory = scratch("write");
        let path = directory.join("a/b/c.pack");

        assert_eq!(write(&path.to_string_lossy(), b"x"), Ok(()));
        assert_eq!(std::fs::read(&path).unwrap(), b"x");
        assert!(matches!(
            write(&path.join("d.pack").to_string_lossy(), b"x"),
            Err(Failure::Io(_))
        ));
        assert!(matches!(
            write(&directory.join("a").to_string_lossy(), b"x"),
            Err(Failure::Io(_))
        ));
        assert!(matches!(write("", b"x"), Err(Failure::Io(_))));
    }
}
