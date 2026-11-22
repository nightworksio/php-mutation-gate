//! kqueue's notifications of a watch, on macOS and the BSDs.

use std::collections::{BTreeSet, HashSet};
use std::fs;
use std::path::{Path, PathBuf};
use std::time::Duration;

use kqueue::{Event, EventData, EventFilter, FilterFlag, Ident, Vnode, Watcher};
use rustix::process::{Resource, Rlimit, getrlimit, setrlimit};

use super::{Notices, walked};

/// The most descriptors macOS lets a process raise its limit to where its hard limit is unlimited.
const OPEN_MAX: u64 = 10_240;

/// kqueue's notifications: a descriptor on each directory and file, which tells of its own changes.
pub(super) struct Kqueue {
    watcher: Watcher,
    known: HashSet<PathBuf>,
    skipped: PathBuf,
}

impl Kqueue {
    pub(super) fn armed(paths: &BTreeSet<PathBuf>, skipped: &Path) -> Result<Self, String> {
        raised();
        let watcher = Watcher::new().map_err(|error| format!("cannot start kqueue: {error}"))?;
        let mut armed = Self {
            watcher,
            known: HashSet::new(),
            skipped: skipped.to_path_buf(),
        };
        armed.added(paths)?;

        Ok(armed)
    }

    fn added(&mut self, paths: &BTreeSet<PathBuf>) -> Result<(), String> {
        let flags = FilterFlag::NOTE_DELETE
            | FilterFlag::NOTE_WRITE
            | FilterFlag::NOTE_EXTEND
            | FilterFlag::NOTE_RENAME
            | FilterFlag::NOTE_REVOKE;
        let watcher = &mut self.watcher;
        let known = &mut self.known;
        let mut any = false;

        walked(paths, &self.skipped, &mut |path, _| {
            if known.contains(path) {
                return Ok(());
            }

            if watched(
                watcher.add_filename(path, EventFilter::EVFILT_VNODE, flags),
                path,
            )? {
                known.insert(path.to_path_buf());
                any = true;
            }

            Ok(())
        })?;

        if any {
            self.watcher
                .watch()
                .map_err(|error| format!("cannot arm kqueue: {error}"))?;
        }

        Ok(())
    }

    /// A watched path's change: a path gone or replaced is let go, and
    /// the entries its directory, or it as a directory, holds that are
    /// not watched yet are.
    fn handled(&mut self, path: PathBuf, gone: bool) -> Result<(), String> {
        let rescanned = match fs::symlink_metadata(&path) {
            Ok(metadata) if !gone => metadata.is_dir().then_some(path),
            _ => {
                let _ = self
                    .watcher
                    .remove_filename(&path, EventFilter::EVFILT_VNODE);
                self.known.remove(&path);
                path.parent().map(Path::to_path_buf)
            }
        };
        let Some(directory) = rescanned.filter(|directory| self.known.contains(directory)) else {
            return Ok(());
        };
        let unknown = fs::read_dir(&directory)
            .map(|entries| {
                entries
                    .filter_map(Result::ok)
                    .map(|entry| entry.path())
                    .filter(|entry| !self.known.contains(entry))
                    .collect()
            })
            .unwrap_or_default();

        self.added(&unknown)
    }
}

impl Notices for Kqueue {
    fn next(&mut self, within: Duration) -> Result<bool, String> {
        let mut changed = Vec::new();
        let mut wait = Some(within);

        while let Some(event) = self.watcher.poll(wait) {
            wait = Some(Duration::ZERO);
            changed.extend(changed_by(event)?);
        }

        let any = !changed.is_empty();

        for (path, gone) in changed {
            self.handled(path, gone)?;
        }

        Ok(any)
    }
}

/// Whether a path is watched now: not where it is gone; why not, where it could not be watched.
fn watched(added: std::io::Result<()>, path: &Path) -> Result<bool, String> {
    match added {
        Ok(()) => Ok(true),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(false),
        Err(error) => Err(format!("cannot watch {}: {error}", path.display())),
    }
}

/// The watched path an event tells of a change of, and whether it is gone; none for any other event; why, where
/// kqueue failed.
fn changed_by(event: Event) -> Result<Option<(PathBuf, bool)>, String> {
    match (event.ident, event.data) {
        (_, EventData::Error(error)) => Err(format!("kqueue failed: {error}")),
        (Ident::Filename(_, name), EventData::Vnode(vnode)) => Ok(Some((
            PathBuf::from(name),
            matches!(vnode, Vnode::Delete | Vnode::Rename | Vnode::Revoke),
        ))),
        _ => Ok(None),
    }
}

/// The process's limit of open descriptors raised as far as it may be, each watched path holding one.
fn raised() {
    let limit = getrlimit(Resource::Nofile);
    let most = limit.maximum;

    if setrlimit(
        Resource::Nofile,
        Rlimit {
            current: most,
            maximum: most,
        },
    )
    .is_err()
    {
        let bounded = most.map_or(OPEN_MAX, |most| most.min(OPEN_MAX));

        if let Some(current) = limit.current {
            let _ = setrlimit(
                Resource::Nofile,
                Rlimit {
                    current: Some(current.max(bounded)),
                    maximum: most,
                },
            );
        }
    }
}

pub(super) fn native(
    paths: &BTreeSet<PathBuf>,
    skipped: &Path,
) -> Result<Box<dyn Notices>, String> {
    Kqueue::armed(paths, skipped).map(|notices| Box::new(notices) as Box<dyn Notices>)
}

#[cfg(test)]
mod tests {
    use std::collections::BTreeSet;
    use std::io::{Error, ErrorKind};
    use std::path::{Path, PathBuf};
    use std::time::Duration;

    use kqueue::{Event, EventData, Ident, Vnode};
    use rustix::process::{Resource, Rlimit, getrlimit, setrlimit};

    use super::{Kqueue, Notices, OPEN_MAX, changed_by, raised, watched};

    #[test]
    fn watches_the_entries_a_changed_directory_holds_now() {
        let root = std::env::temp_dir().join(format!("outbreak-kqueue-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&root);
        std::fs::create_dir_all(root.join("src")).unwrap();
        let mut armed =
            Kqueue::armed(&BTreeSet::from([root.join("src")]), &root.join("skipped")).unwrap();
        std::fs::write(root.join("src/A.php"), "").unwrap();

        assert_eq!(armed.handled(root.join("src"), false), Ok(()));
        assert!(armed.known.contains(&root.join("src/A.php")));
        std::fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn watches_a_file_replaced_at_its_path_anew() {
        let root =
            std::env::temp_dir().join(format!("outbreak-kqueue-replaced-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&root);
        std::fs::create_dir_all(root.join("src")).unwrap();
        std::fs::write(root.join("src/A.php"), "").unwrap();
        let mut armed =
            Kqueue::armed(&BTreeSet::from([root.join("src")]), &root.join("skipped")).unwrap();
        std::fs::write(root.join("src/A.new"), "").unwrap();
        std::fs::rename(root.join("src/A.new"), root.join("src/A.php")).unwrap();
        while armed
            .watcher
            .poll(Some(Duration::from_millis(100)))
            .is_some()
        {}

        assert_eq!(armed.handled(root.join("src/A.php"), true), Ok(()));
        std::fs::write(root.join("src/A.php"), "<?php").unwrap();
        assert_eq!(armed.next(Duration::from_secs(1)), Ok(true));
        std::fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn watches_no_path_that_is_gone_and_says_why_it_cannot_watch_one() {
        let path = Path::new("/p");

        assert_eq!(watched(Ok(()), path), Ok(true));
        assert_eq!(
            watched(Err(Error::from(ErrorKind::NotFound)), path),
            Ok(false)
        );
        assert!(
            watched(Err(Error::from(ErrorKind::PermissionDenied)), path)
                .is_err_and(|why| why.starts_with("cannot watch /p"))
        );
    }

    #[test]
    fn tells_which_path_an_event_changed_and_whether_it_is_gone() {
        let event = |data| Event {
            ident: Ident::Filename(0, String::from("/p")),
            data,
        };

        assert_eq!(
            changed_by(event(EventData::Vnode(Vnode::Write))),
            Ok(Some((PathBuf::from("/p"), false)))
        );
        assert_eq!(
            changed_by(event(EventData::Vnode(Vnode::Rename))),
            Ok(Some((PathBuf::from("/p"), true)))
        );
        assert_eq!(
            changed_by(Event {
                ident: Ident::Fd(0),
                data: EventData::Vnode(Vnode::Write),
            }),
            Ok(None)
        );
        assert!(
            changed_by(event(EventData::Error(Error::other("lost"))))
                .is_err_and(|why| why == "kqueue failed: lost")
        );
    }

    #[test]
    fn raises_its_descriptor_limit_as_far_as_it_may() {
        let limit = getrlimit(Resource::Nofile);
        setrlimit(
            Resource::Nofile,
            Rlimit {
                current: Some(256),
                maximum: limit.maximum,
            },
        )
        .unwrap();

        raised();

        let bounded = limit.maximum.map_or(OPEN_MAX, |most| most.min(OPEN_MAX));

        assert!(
            getrlimit(Resource::Nofile)
                .current
                .is_none_or(|current| current >= bounded)
        );
    }
}
