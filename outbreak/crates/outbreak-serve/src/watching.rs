//! The daemon's native watch of the files (ADR-0133, decision 7): the
//! directories a client names under the root, each watched with every file
//! and directory beneath it through the platform's own notifications, inotify
//! on Linux and kqueue on macOS and the BSDs. A burst of changes counts once
//! it settled for the debounce, as one more generation; a client asks for the
//! generation after the one it saw, and waits for it at most as long as it
//! says. Version control's own directories, the daemon's workspace and links
//! are not followed. Where the watcher cannot arm, or stops, every later
//! answer says why, and the client looks at the files itself.

use std::collections::BTreeSet;
use std::fs;
use std::io::ErrorKind;
use std::path::{Component, Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Condvar, Mutex, MutexGuard, PoisonError};
use std::thread;
use std::time::{Duration, Instant};

use crate::server::TICK;

/// The directories version control keeps its own state in, never watched.
const UNWATCHED: [&str; 3] = [".git", ".hg", ".svn"];

/// What the daemon answers a client waiting for a change.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Noticed {
    /// The generation of changes now, which is past the one the client saw where a change settled in its wait.
    Changed(u64),
    /// Why the daemon watches nothing.
    Unwatched(String),
}

/// The watch a daemon keeps for its clients.
pub struct Watching {
    root: PathBuf,
    skipped: PathBuf,
    debounce: Duration,
    armed: Mutex<Armed>,
    moved: Condvar,
}

#[derive(Default)]
struct Armed {
    paths: Option<BTreeSet<PathBuf>>,
    generation: u64,
    unwatched: Option<String>,
    running: Option<Arc<AtomicBool>>,
}

/// A platform's notifications of the files beneath the watched directories.
trait Notices: Send {
    /// Whether a change arrived within this long.
    fn next(&mut self, within: Duration) -> Result<bool, String>;
}

impl Watching {
    /// A watch of directories under this root, never of `skipped`, whose changes settle for `debounce`.
    #[must_use]
    pub fn new(root: PathBuf, skipped: PathBuf, debounce: Duration) -> Arc<Self> {
        Arc::new(Self { root, skipped, debounce, armed: Mutex::new(Armed::default()), moved: Condvar::new() })
    }

    /// The generation once a change settled past `since`, or once `wait`
    /// passed; the directories, each relative to the root, watched from now
    /// on where they are not the ones watched already, which counts as a
    /// change.
    pub fn changed(self: &Arc<Self>, paths: &[String], since: u64, wait: Duration) -> Noticed {
        let wanted = match self.wanted(paths) {
            Ok(wanted) => wanted,
            Err(why) => return Noticed::Unwatched(why),
        };
        let mut armed = locked(&self.armed);

        if armed.paths.as_ref() != Some(&wanted) {
            self.arm(&mut armed, wanted);
        }

        let until = Instant::now() + wait;

        loop {
            if let Some(why) = &armed.unwatched {
                return Noticed::Unwatched(why.clone());
            }

            let left = until.saturating_duration_since(Instant::now());

            if armed.generation > since || left.is_zero() {
                return Noticed::Changed(armed.generation);
            }

            armed = self.moved.wait_timeout(armed, left).unwrap_or_else(PoisonError::into_inner).0;
        }
    }

    /// Stops the watcher, where one runs.
    pub fn stop(&self) {
        if let Some(running) = locked(&self.armed).running.take() {
            running.store(true, Ordering::SeqCst);
        }
    }

    fn wanted(&self, paths: &[String]) -> Result<BTreeSet<PathBuf>, String> {
        paths
            .iter()
            .map(|path| {
                let inside = Path::new(path).components().all(|part| matches!(part, Component::Normal(_) | Component::CurDir));

                if inside {
                    Ok(self.root.join(path))
                } else {
                    Err(format!("{path} is not a directory under the root"))
                }
            })
            .collect()
    }

    fn arm(self: &Arc<Self>, armed: &mut MutexGuard<'_, Armed>, wanted: BTreeSet<PathBuf>) {
        if let Some(running) = armed.running.take() {
            running.store(true, Ordering::SeqCst);
        }

        if armed.paths.is_some() {
            armed.generation += 1;
        }

        let started = native(&wanted, &self.skipped).map(|notices| {
            let stopped = Arc::new(AtomicBool::new(false));
            let watching = Arc::clone(self);
            let stopping = Arc::clone(&stopped);
            thread::spawn(move || watching.watched(notices, &stopping));
            stopped
        });

        armed.paths = Some(wanted);
        (armed.running, armed.unwatched) = match started {
            Ok(stopped) => (Some(stopped), None),
            Err(why) => (None, Some(why)),
        };
        self.moved.notify_all();
    }

    /// Counts each burst of changes once it settled, until told to stop or the notifications fail.
    fn watched(&self, mut notices: Box<dyn Notices>, stopped: &AtomicBool) {
        loop {
            let settled = settled(notices.as_mut(), self.debounce, stopped);
            let mut armed = locked(&self.armed);

            if stopped.load(Ordering::SeqCst) {
                return;
            }

            match settled {
                Ok(false) => continue,
                Ok(true) => armed.generation += 1,
                Err(why) => {
                    armed.unwatched = Some(why);
                    armed.running = None;
                    self.moved.notify_all();
                    return;
                }
            }

            self.moved.notify_all();
        }
    }
}

/// Whether a change arrived within a tick, once the changes after it have settled for the debounce.
fn settled(notices: &mut dyn Notices, debounce: Duration, stopped: &AtomicBool) -> Result<bool, String> {
    if !notices.next(TICK)? {
        return Ok(false);
    }

    while !stopped.load(Ordering::SeqCst) && notices.next(debounce)? {}

    Ok(true)
}

/// Every directory and file beneath each of these, each once, none beneath
/// `skipped`, a version control directory or a link; a directory gone by the
/// time it is read holds nothing.
fn walked(paths: &BTreeSet<PathBuf>, skipped: &Path, mut each: impl FnMut(&Path, bool) -> Result<(), String>) -> Result<(), String> {
    let mut left: Vec<PathBuf> = paths.iter().cloned().collect();

    while let Some(path) = left.pop() {
        let Ok(metadata) = fs::symlink_metadata(&path) else {
            continue;
        };

        if metadata.is_symlink() || !watched(&path, skipped) {
            continue;
        }

        let directory = metadata.is_dir();
        each(&path, directory)?;

        if directory {
            match fs::read_dir(&path) {
                Ok(entries) => left.extend(entries.filter_map(Result::ok).map(|entry| entry.path())),
                Err(error) if error.kind() == ErrorKind::NotFound => {}
                Err(error) => return Err(format!("cannot read {}: {error}", path.display())),
            }
        }
    }

    Ok(())
}

fn watched(path: &Path, skipped: &Path) -> bool {
    !path.starts_with(skipped) && path.file_name().is_none_or(|name| !UNWATCHED.iter().any(|unwatched| name == *unwatched))
}

fn locked<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(PoisonError::into_inner)
}

#[cfg(any(target_os = "linux", target_os = "android"))]
fn native(paths: &BTreeSet<PathBuf>, skipped: &Path) -> Result<Box<dyn Notices>, String> {
    inotify::Inotify::armed(paths, skipped).map(|notices| Box::new(notices) as Box<dyn Notices>)
}

#[cfg(any(
    target_os = "macos",
    target_os = "ios",
    target_os = "freebsd",
    target_os = "openbsd",
    target_os = "netbsd",
    target_os = "dragonfly"
))]
fn native(paths: &BTreeSet<PathBuf>, skipped: &Path) -> Result<Box<dyn Notices>, String> {
    kqueue::Kqueue::armed(paths, skipped).map(|notices| Box::new(notices) as Box<dyn Notices>)
}

#[cfg(not(any(
    target_os = "linux",
    target_os = "android",
    target_os = "macos",
    target_os = "ios",
    target_os = "freebsd",
    target_os = "openbsd",
    target_os = "netbsd",
    target_os = "dragonfly"
)))]
fn native(_: &BTreeSet<PathBuf>, _: &Path) -> Result<Box<dyn Notices>, String> {
    Err(String::from("this platform has no notifications the daemon watches through"))
}

#[cfg(any(target_os = "linux", target_os = "android"))]
mod inotify {
    use std::collections::{BTreeSet, HashMap};
    use std::ffi::OsStr;
    use std::mem::MaybeUninit;
    use std::os::unix::ffi::OsStrExt as _;
    use std::os::fd::OwnedFd;
    use std::path::{Path, PathBuf};
    use std::time::Duration;

    use rustix::event::{PollFd, PollFlags, Timespec, poll};
    use rustix::fs::inotify::{self, CreateFlags, ReadFlags, Reader, WatchFlags};
    use rustix::io::Errno;

    use super::{Notices, walked};

    /// The bytes read from the notifications at once.
    const BUFFER: usize = 1 << 16;

    /// Linux's notifications: a watch on each directory, which tells of its entries' changes.
    pub(super) struct Inotify {
        fd: OwnedFd,
        directories: HashMap<i32, PathBuf>,
        paths: BTreeSet<PathBuf>,
        skipped: PathBuf,
        buffer: Vec<MaybeUninit<u8>>,
    }

    impl Inotify {
        pub(super) fn armed(paths: &BTreeSet<PathBuf>, skipped: &Path) -> Result<Self, String> {
            let fd = inotify::init(CreateFlags::CLOEXEC | CreateFlags::NONBLOCK)
                .map_err(|error| format!("cannot start inotify: {error}"))?;
            let mut armed = Self {
                fd,
                directories: HashMap::new(),
                paths: paths.clone(),
                skipped: skipped.to_path_buf(),
                buffer: vec![MaybeUninit::uninit(); BUFFER],
            };
            armed.added(paths)?;

            Ok(armed)
        }

        fn added(&mut self, paths: &BTreeSet<PathBuf>) -> Result<(), String> {
            let flags = WatchFlags::MODIFY
                | WatchFlags::CLOSE_WRITE
                | WatchFlags::CREATE
                | WatchFlags::DELETE
                | WatchFlags::DELETE_SELF
                | WatchFlags::MOVED_FROM
                | WatchFlags::MOVED_TO
                | WatchFlags::MOVE_SELF
                | WatchFlags::ONLYDIR
                | WatchFlags::DONT_FOLLOW;
            let fd = &self.fd;
            let directories = &mut self.directories;

            walked(paths, &self.skipped, |path, directory| {
                if !directory {
                    return Ok(());
                }

                match inotify::add_watch(fd, path, flags) {
                    Ok(watch) => {
                        directories.insert(watch, path.to_path_buf());
                        Ok(())
                    }
                    Err(Errno::NOENT) => Ok(()),
                    Err(Errno::NOSPC) => Err(String::from(
                        "inotify has no watch left: fs.inotify.max_user_watches is too low for the trees",
                    )),
                    Err(error) => Err(format!("cannot watch {}: {error}", path.display())),
                }
            })
        }
    }

    impl Notices for Inotify {
        fn next(&mut self, within: Duration) -> Result<bool, String> {
            let timeout = Timespec::try_from(within).map_err(|error| format!("cannot wait {within:?}: {error}"))?;
            let mut fds = [PollFd::new(&self.fd, PollFlags::IN)];

            match poll(&mut fds, Some(&timeout)) {
                Ok(0) | Err(Errno::INTR) => return Ok(false),
                Ok(_) => {}
                Err(error) => return Err(format!("cannot wait for inotify: {error}")),
            }

            let mut created = BTreeSet::new();
            let mut overflowed = false;
            let mut reader = Reader::new(&self.fd, &mut self.buffer);

            loop {
                let event = match reader.next() {
                    Ok(event) => event,
                    Err(Errno::AGAIN | Errno::INTR) => break,
                    Err(error) => return Err(format!("cannot read inotify: {error}")),
                };
                let flags = event.events();

                if flags.contains(ReadFlags::QUEUE_OVERFLOW) {
                    overflowed = true;
                } else if flags.contains(ReadFlags::IGNORED) {
                    self.directories.remove(&event.wd());
                } else if flags.contains(ReadFlags::ISDIR)
                    && flags.intersects(ReadFlags::CREATE | ReadFlags::MOVED_TO)
                    && let (Some(directory), Some(name)) = (self.directories.get(&event.wd()), event.file_name())
                {
                    created.insert(directory.join(OsStr::from_bytes(name.to_bytes())));
                }

            }

            if overflowed {
                created.clone_from(&self.paths);
            }

            self.added(&created)?;

            Ok(true)
        }
    }
}

#[cfg(any(
    target_os = "macos",
    target_os = "ios",
    target_os = "freebsd",
    target_os = "openbsd",
    target_os = "netbsd",
    target_os = "dragonfly"
))]
mod kqueue {
    use std::collections::{BTreeSet, HashSet};
    use std::fs;
    use std::path::{Path, PathBuf};
    use std::time::Duration;

    use kqueue::{EventData, EventFilter, FilterFlag, Ident, Vnode, Watcher};
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
            let mut armed = Self { watcher, known: HashSet::new(), skipped: skipped.to_path_buf() };
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

            walked(paths, &self.skipped, |path, _| {
                if known.contains(path) {
                    return Ok(());
                }

                match watcher.add_filename(path, EventFilter::EVFILT_VNODE, flags) {
                    Ok(()) => {
                        known.insert(path.to_path_buf());
                        any = true;
                        Ok(())
                    }
                    Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
                    Err(error) => Err(format!("cannot watch {}: {error}", path.display())),
                }
            })?;

            if any {
                self.watcher.watch().map_err(|error| format!("cannot arm kqueue: {error}"))?;
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
                    let _ = self.watcher.remove_filename(&path, EventFilter::EVFILT_VNODE);
                    self.known.remove(&path);
                    path.parent().map(Path::to_path_buf)
                }
            };
            let Some(directory) = rescanned.filter(|directory| self.known.contains(directory)) else {
                return Ok(());
            };
            let unknown = fs::read_dir(&directory)
                .map(|entries| {
                    entries.filter_map(Result::ok).map(|entry| entry.path()).filter(|entry| !self.known.contains(entry)).collect()
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

                match (event.ident, event.data) {
                    (_, EventData::Error(error)) => return Err(format!("kqueue failed: {error}")),
                    (Ident::Filename(_, name), EventData::Vnode(vnode)) => {
                        changed.push((PathBuf::from(name), matches!(vnode, Vnode::Delete | Vnode::Rename | Vnode::Revoke)));
                    }
                    _ => {}
                }
            }

            let any = !changed.is_empty();

            for (path, gone) in changed {
                self.handled(path, gone)?;
            }

            Ok(any)
        }
    }

    /// The process's limit of open descriptors raised as far as it may be, each watched path holding one.
    fn raised() {
        let limit = getrlimit(Resource::Nofile);
        let most = limit.maximum;

        if setrlimit(Resource::Nofile, Rlimit { current: most, maximum: most }).is_err() {
            let bounded = most.map_or(OPEN_MAX, |most| most.min(OPEN_MAX));

            if limit.current.is_some_and(|current| current < bounded) {
                let _ = setrlimit(Resource::Nofile, Rlimit { current: Some(bounded), maximum: most });
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use std::fs;
    use std::path::{Path, PathBuf};
    use std::sync::Arc;
    use std::time::Duration;

    use super::{Noticed, Watching};

    const SETTLED: Duration = Duration::from_secs(5);

    const QUIET: Duration = Duration::from_millis(300);

    fn fresh(name: &str) -> PathBuf {
        let root = std::env::temp_dir().join(format!("outbreak-watching-{name}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&root);
        fs::create_dir_all(root.join("src/deep")).unwrap();
        fs::create_dir_all(root.join(".mutation-gate")).unwrap();
        fs::create_dir_all(root.join(".git")).unwrap();
        fs::write(root.join("src/deep/A.php"), "<?php\n").unwrap();
        root
    }

    fn watching(root: &Path) -> Arc<Watching> {
        Watching::new(root.to_path_buf(), root.join(".mutation-gate"), Duration::from_millis(20))
    }

    fn generation(noticed: Noticed) -> u64 {
        match noticed {
            Noticed::Changed(generation) => generation,
            Noticed::Unwatched(why) => panic!("{why}"),
        }
    }

    #[test]
    fn counts_a_settled_change_beneath_a_watched_directory_and_none_in_what_it_skips() {
        let root = fresh("change");
        let watching = watching(&root);
        let paths = [String::from(".")];
        let armed = generation(watching.changed(&paths, 0, Duration::ZERO));

        fs::write(root.join("src/deep/A.php"), "<?php\necho 1;\n").unwrap();
        let written = generation(watching.changed(&paths, armed, SETTLED));
        assert!(written > armed);

        fs::write(root.join(".mutation-gate/journal.bin"), "x").unwrap();
        fs::write(root.join(".git/index"), "x").unwrap();
        assert_eq!(generation(watching.changed(&paths, written, QUIET)), written);

        fs::create_dir(root.join("src/new")).unwrap();
        let made = generation(watching.changed(&paths, written, SETTLED));
        assert!(made > written);

        fs::write(root.join("src/new/B.php"), "<?php\n").unwrap();
        let added = generation(watching.changed(&paths, made, SETTLED));
        assert!(added > made);

        fs::write(root.join("src/new/B.php.tmp"), "<?php\necho 2;\n").unwrap();
        fs::rename(root.join("src/new/B.php.tmp"), root.join("src/new/B.php")).unwrap();
        let replaced = generation(watching.changed(&paths, added, SETTLED));
        assert!(replaced > added);

        fs::write(root.join("src/new/B.php"), "<?php\necho 3;\n").unwrap();
        assert!(generation(watching.changed(&paths, replaced, SETTLED)) > replaced);
        watching.stop();
    }

    #[test]
    fn counts_other_directories_as_a_change_and_refuses_one_outside_the_root() {
        let root = fresh("other");
        let watching = watching(&root);

        assert_eq!(watching.changed(&[String::from("src")], 0, Duration::ZERO), Noticed::Changed(0));
        assert_eq!(watching.changed(&[String::from("src/deep")], 0, Duration::ZERO), Noticed::Changed(1));
        assert_eq!(
            watching.changed(&[String::from("../elsewhere")], 0, Duration::ZERO),
            Noticed::Unwatched(String::from("../elsewhere is not a directory under the root"))
        );
        assert!(matches!(watching.changed(&[String::from("/etc")], 0, Duration::ZERO), Noticed::Unwatched(_)));
        watching.stop();
    }
}
