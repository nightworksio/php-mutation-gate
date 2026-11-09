//! What the gate asks of the cache, as JSON on the binary's input:
//! `{"store": "actions"|"oci", "registry": <repository>, "base": <digest>,
//! "limits": {"packed": <bytes>, "unpacked": <bytes>}, "packs": {<class>:
//! <path>, …}, "restored": {<class>: <path>, …}}`. `registry` is read under
//! `oci` alone; every other key is read once, and none other is.

use std::collections::{BTreeMap, HashSet};

use outbreak_map::json::{Cursor, Number, Refused};

use crate::Failure;

/// Where the packs live.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Store {
    /// The Actions cache, which the action restores into the gate's directory and saves from it.
    Actions,
    /// An OCI registry, reached over HTTPS.
    Oci,
}

/// The bytes a pack is held to, packed and unpacked, as the gate holds a ledger.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Limits {
    /// The most bytes a pack's gzipped file may hold.
    pub packed: u64,
    /// The most bytes a pack's text may hold.
    pub unpacked: u64,
}

/// A request to the cache.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    /// Where the packs live.
    pub store: Store,
    /// The repository of the registry, such as `ghcr.io/owner/proofs`, under `oci`.
    pub registry: Option<String>,
    /// The base every proof of a pack is at.
    pub base: String,
    /// What a pack is held to.
    pub limits: Limits,
    /// Each class's pack: where it is pulled into, or the entries that are pushed.
    pub packs: BTreeMap<String, String>,
    /// Where the Actions cache restored each class's pack, and where a push leaves the merged one.
    pub restored: BTreeMap<String, String>,
}

/// The request a text holds.
///
/// # Errors
///
/// Refuses a text that is not such a request.
pub fn read(text: &[u8]) -> Result<Request, Failure> {
    read_json(text).map_err(|refused| {
        Failure::Refused(format!(
            "the cache's request: expected {} at byte {}",
            refused.expected, refused.at
        ))
    })
}

fn read_json(text: &[u8]) -> Result<Request, Refused> {
    let mut json = Cursor::over(text)?;
    let mut members = json.object()?;
    let mut seen = HashSet::new();
    let (mut store, mut registry, mut base, mut limits) = (None, None, None, None);
    let (mut packs, mut restored) = (None, None);

    while let Some(key) = members.next(&mut json)? {
        if !seen.insert(key.text.clone()) {
            return Err(Refused {
                at: key.raw.start,
                expected: "each key once",
            });
        }

        match key.text.as_str() {
            "store" => store = Some(store_of(&mut json)?),
            "registry" => registry = Some(json.string()?),
            "base" => base = Some(json.string()?),
            "limits" => limits = Some(limits_of(&mut json)?),
            "packs" => packs = Some(paths_of(&mut json)?),
            "restored" => restored = Some(paths_of(&mut json)?),
            _ => {
                return Err(Refused {
                    at: key.raw.start,
                    expected: "a key of the cache's request",
                });
            }
        }
    }

    json.end()?;

    match (store, base, limits, packs) {
        (Some(store), Some(base), Some(limits), Some(packs)) => Ok(Request {
            store,
            registry,
            base,
            limits,
            packs,
            restored: restored.unwrap_or_default(),
        }),
        _ => Err(Refused {
            at: 0,
            expected: "a store, a base, limits and packs",
        }),
    }
}

fn store_of(json: &mut Cursor<'_>) -> Result<Store, Refused> {
    let at = json.at();

    match json.string()?.as_str() {
        "actions" => Ok(Store::Actions),
        "oci" => Ok(Store::Oci),
        _ => Err(Refused {
            at,
            expected: "actions or oci",
        }),
    }
}

fn limits_of(json: &mut Cursor<'_>) -> Result<Limits, Refused> {
    let mut members = json.object()?;
    let (mut packed, mut unpacked) = (None, None);

    while let Some(key) = members.next(json)? {
        match key.text.as_str() {
            "packed" => packed = Some(whole(json)?),
            "unpacked" => unpacked = Some(whole(json)?),
            _ => {
                return Err(Refused {
                    at: key.raw.start,
                    expected: "packed or unpacked",
                });
            }
        }
    }

    match (packed, unpacked) {
        (Some(packed), Some(unpacked)) => Ok(Limits { packed, unpacked }),
        _ => Err(Refused {
            at: json.at(),
            expected: "both limits",
        }),
    }
}

fn whole(json: &mut Cursor<'_>) -> Result<u64, Refused> {
    let at = json.at();

    match json.number()? {
        Number::Whole(value) => Ok(value),
        Number::Other { .. } => Err(Refused {
            at,
            expected: "a whole number of bytes",
        }),
    }
}

fn paths_of(json: &mut Cursor<'_>) -> Result<BTreeMap<String, String>, Refused> {
    let mut members = json.object()?;
    let mut paths = BTreeMap::new();

    while let Some(key) = members.next(json)? {
        if key.text != "trusted" && key.text != "branch" {
            return Err(Refused {
                at: key.raw.start,
                expected: "trusted or branch",
            });
        }

        let path = json.string()?;
        paths.insert(key.text, path);
    }

    Ok(paths)
}

#[cfg(test)]
mod tests {
    use super::{Store, read};

    #[test]
    fn reads_a_pull_under_actions() {
        let request = read(
            br#"{"store":"actions","base":"ab","limits":{"packed":10,"unpacked":20},"packs":{"trusted":"/a/t.pack"},"restored":{"trusted":"/a/r"}}"#,
        );

        assert!(matches!(request, Ok(ref read) if read.store == Store::Actions && read.limits.unpacked == 20));
    }

    #[test]
    fn refuses_a_class_it_does_not_know() {
        let request = read(
            br#"{"store":"oci","base":"ab","limits":{"packed":1,"unpacked":1},"packs":{"anyone":"/a"}}"#,
        );

        assert!(request.is_err());
    }
}
