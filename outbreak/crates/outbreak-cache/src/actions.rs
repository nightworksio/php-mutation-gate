//! The packs under the Actions cache. The action restores each class's newest
//! pack into a directory of the gate's, `<restored>/<class>.pack`, before the
//! plan, and saves that directory after the verdict: a pull copies a restored
//! pack of the request's base to where the gate reads it, and a push merges a
//! verdict's entries into the restored pack, leaving the merged one there.

use std::fs;
use std::path::Path;

use crate::Failure;
use crate::pack::{self, Pack};
use crate::request::Request;

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

    for (class, entries) in &request.packs {
        let Some(directory) = request.restored.get(class) else {
            continue;
        };
        let bytes = fs::read(entries).map_err(|error| Failure::Io(format!("cannot read {entries}: {error}")))?;
        let newer = pack::read(&bytes, request.limits)?;

        if newer.class != *class || newer.base != request.base {
            return Err(Failure::Refused(format!("{entries} is no {class} pack at the request's base")));
        }

        let older = restored(request, class).unwrap_or_else(|| Pack::empty(class, &request.base));
        fs::create_dir_all(directory).map_err(|error| Failure::Io(format!("cannot make {directory}: {error}")))?;
        write(&packed_in(directory, class), &older.merged(newer).bytes(request.limits)?)?;
        pushed += 1;
    }

    Ok(pushed)
}

/// The pack of a class the action restored, where it holds proofs at the request's base.
fn restored(request: &Request, class: &str) -> Option<Pack> {
    let directory = request.restored.get(class)?;
    let bytes = fs::read(packed_in(directory, class)).ok()?;
    let read = pack::read(&bytes, request.limits).ok()?;

    (read.class == class && read.base == request.base).then_some(read)
}

fn packed_in(directory: &str, class: &str) -> String {
    Path::new(directory).join(format!("{class}.pack")).to_string_lossy().into_owned()
}

fn write(path: &str, bytes: &[u8]) -> Result<(), Failure> {
    if let Some(parent) = Path::new(path).parent() {
        fs::create_dir_all(parent).map_err(|error| Failure::Io(format!("cannot make {}: {error}", parent.display())))?;
    }

    fs::write(path, bytes).map_err(|error| Failure::Io(format!("cannot write {path}: {error}")))
}
