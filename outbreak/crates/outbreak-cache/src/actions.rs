//! The packs under the Actions cache. The action restores each class's newest
//! pack into a directory of the gate's, `<restored>/<class>.pack`, before the
//! plan, and saves that directory after the verdict: a pull copies a restored
//! pack of the request's base to where the gate reads it, and a push merges a
//! verdict's entries into the restored pack, leaving the merged one there.

use std::fs;
use std::path::Path;

use crate::pack::{self, Pack};
use crate::request::Request;
use crate::{Failure, entries, write};

/// Each class's restored pack at the request's base copied to its path: how many were.
///
/// # Errors
///
/// Fails where a pack that was read cannot be written.
pub fn pull(request: &Request) -> Result<usize, Failure> {
    let mut pulled = 0;

    for (class, into) in &request.packs {
        if let Some(restored) = restored(request, class) {
            write(into, &restored.bytes(request.limits)?)?;
            pulled += 1;
        }
    }

    Ok(pulled)
}

/// Each class's entries merged into its restored pack, the merged pack left where it was restored: how many were.
///
/// # Errors
///
/// Fails where the entries cannot be read, or the merged pack cannot be written.
pub fn push(request: &Request) -> Result<usize, Failure> {
    let mut pushed = 0;

    for (class, path) in &request.packs {
        let Some(directory) = request.restored.get(class) else {
            continue;
        };
        let newer = entries(request, class, path)?;
        let older = restored(request, class).unwrap_or_else(|| Pack::empty(class, &request.base));
        let merged = older.merged(newer).bytes(request.limits)?;
        write(&packed_in(directory, class), &merged)?;
        pushed += 1;
    }

    Ok(pushed)
}

/// The pack of a class the action restored, where it holds proofs at the request's base.
fn restored(request: &Request, class: &str) -> Option<Pack> {
    let directory = request.restored.get(class)?;
    let bytes = fs::read(packed_in(directory, class)).ok()?;
    let read = pack::read(&bytes, request.limits).ok()?;

    request.holds(&read, class).then_some(read)
}

fn packed_in(directory: &str, class: &str) -> String {
    Path::new(directory)
        .join(format!("{class}.pack"))
        .to_string_lossy()
        .into_owned()
}

#[cfg(test)]
mod tests {
    use super::{pull, push};
    use crate::Failure;
    use crate::request::Store;
    use crate::tests::{packed, request, scratch};

    #[test]
    fn pulls_each_restored_pack_at_the_base() {
        let directory = scratch("actions-pull");
        let into = directory.join("into/trusted.pack");
        let restored = directory.join("restored");
        let mut asked = request(Store::Actions, &into.to_string_lossy());

        assert_eq!(pull(&asked), Ok(0));

        asked.restored.insert(
            "trusted".to_owned(),
            restored.to_string_lossy().into_owned(),
        );

        assert_eq!(pull(&asked), Ok(0));

        std::fs::create_dir_all(&restored).unwrap();
        std::fs::write(
            restored.join("trusted.pack"),
            packed("trusted", "base0", "a"),
        )
        .unwrap();

        assert_eq!(pull(&asked), Ok(0));

        asked.newest = true;

        assert_eq!(pull(&asked), Ok(1));
        assert_eq!(
            crate::pack::read(&std::fs::read(&into).unwrap(), asked.limits)
                .unwrap()
                .base,
            "base0"
        );
    }

    #[test]
    fn merges_each_class_s_entries_into_its_restored_pack() {
        let directory = scratch("actions-push");
        let entries = directory.join("entries.pack");
        let restored = directory.join("restored");
        let mut asked = request(Store::Actions, &entries.to_string_lossy());
        std::fs::write(&entries, packed("trusted", "base1", "b")).unwrap();

        assert_eq!(push(&asked), Ok(0));

        asked.restored.insert(
            "trusted".to_owned(),
            restored.to_string_lossy().into_owned(),
        );

        assert_eq!(push(&asked), Ok(1));

        std::fs::write(&entries, packed("trusted", "base1", "c")).unwrap();

        assert_eq!(push(&asked), Ok(1));

        let merged = crate::pack::read(
            &std::fs::read(restored.join("trusted.pack")).unwrap(),
            asked.limits,
        )
        .unwrap();

        assert_eq!(merged.len(), 2);

        std::fs::write(&entries, packed("branch", "base1", "c")).unwrap();

        assert!(matches!(push(&asked), Err(Failure::Refused(_))));
    }
}
