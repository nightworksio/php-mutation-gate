//! The daemon itself (ADR-0133, decisions 2 to 4): one for a project's root,
//! held by a lock on `.mutation-gate/outbreak/serve.lock`, answering on
//! `.mutation-gate/outbreak/state.sock` in a directory kept to its owner, or,
//! where that path is longer than a socket's address holds, on
//! `/tmp/mutation-gate-<uid>/<digest of the root>.sock`. It
//! reads its state from the snapshot and the journal there, writes a fresh
//! snapshot, journals what it is asked to keep, syncing the journal at most
//! once every `sync_every`, writes a snapshot again while no client is
//! connected at most once every `snapshot_every`, and once more as it stops.
//! It watches the directories a client waiting for a change names (decision
//! 7), and the machine's power source (decision 8), a waiting client counting
//! as no client connected. It lives as long as
//! the child it starts, where it starts one, or until no request came for
//! `idle`, or it is asked to stop.
//!
//! It refuses `.mutation-gate` or its own directory where either is a link
//! or not a directory of the user it runs as, and opens each of its files
//! without following a link, refusing one that is not a regular file of that
//! user; a snapshot is written to a name no file holds yet, then renamed
//! into place.

use std::fmt::Write as _;
use std::fs::{self, DirBuilder, File, Metadata, OpenOptions, Permissions, TryLockError};
use std::io::{BufRead as _, BufReader, ErrorKind, Read as _, Write as _};
use std::os::unix::ffi::OsStrExt as _;
use std::os::unix::fs::{
    DirBuilderExt as _, MetadataExt as _, OpenOptionsExt as _, PermissionsExt as _,
};
use std::os::unix::net::{SocketAddr, UnixListener, UnixStream};
use std::os::unix::process::CommandExt as _;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, MutexGuard, PoisonError};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use sha2::{Digest as _, Sha256};

use crate::Options;
use crate::journal::{self, Record};
use crate::protocol;
use crate::state::State;
use crate::watching::{Noticed, Watching};

/// The directory under a project's root the daemon keeps its files in.
pub const DIRECTORY: &str = ".mutation-gate/outbreak";

/// The gate's directory under a project's root, which holds the daemon's.
const WORKSPACE: &str = ".mutation-gate";

/// The socket the daemon answers on, in its directory.
pub const SOCKET: &str = "state.sock";

/// The longest path a Unix socket's address holds, its closing NUL left out.
#[cfg(target_os = "linux")]
const ADDRESS: usize = 107;

/// The longest path a Unix socket's address holds, its closing NUL left out.
#[cfg(not(target_os = "linux"))]
const ADDRESS: usize = 103;

/// The directory a socket goes in whose path under the root is too long, for each user.
const SHORT: &str = "/tmp/mutation-gate-";

/// How many bytes of the root's SHA-256, in hex, name a socket in the short directory.
const NAMED_BY: usize = 8;

const LOCK: &str = "serve.lock";

const SNAPSHOT: &str = "state.bin";

const JOURNAL: &str = "journal.bin";

/// The longest request line the daemon reads.
const LONGEST_LINE: u64 = 1 << 26;

/// How often the daemon looks at its clocks, and its watcher whether it is to stop, while each waits.
pub(crate) const TICK: Duration = Duration::from_millis(200);

struct Kept {
    state: State,
    journal: File,
    unsynced: bool,
    journalled: bool,
    synced: Instant,
    snapshotted: Instant,
}

/// The child a daemon lives as long as, while it runs.
type Held = Arc<Mutex<Option<Child>>>;

struct Shared {
    kept: Mutex<Kept>,
    connected: AtomicUsize,
    asked: Mutex<Instant>,
    stopping: AtomicBool,
    watching: Arc<Watching>,
    power: Arc<Watching>,
}

/// Starts a daemon in a process of its own group, which a terminal's
/// interrupt does not reach, with no streams, and returns its process id.
///
/// # Errors
///
/// Says why the daemon could not be started.
pub fn detached(binary: &Path, arguments: &[String]) -> Result<u32, String> {
    Command::new(binary)
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .process_group(0)
        .spawn()
        .map(|child| child.id())
        .map_err(|error| format!("cannot start the daemon: {error}"))
}

/// Serves a project's state until it stops.
///
/// # Errors
///
/// Says why it cannot serve: its directory cannot be kept to its owner,
/// another daemon serves the root, or its files or socket cannot be made.
pub fn serve(options: &Options, contract: u32) -> Result<(), String> {
    let euid = rustix::process::geteuid().as_raw();
    owned_directory(&options.root.join(WORKSPACE), euid)?;
    let directory = options.root.join(DIRECTORY);
    owned_directory(&directory, euid)?;
    fs::set_permissions(&directory, Permissions::from_mode(0o700))
        .map_err(failed("keep to its owner", &directory))?;
    let lock = owned(&directory.join(LOCK), false, euid)?;

    lock.try_lock()
        .map_err(|error| unlocked(&error, &options.root, &directory))?;

    let state = read(&directory, euid);
    snapshotted(&directory, &state, euid)?;
    let journal = owned(&directory.join(JOURNAL), true, euid)?;
    let socket = socket(&options.root, euid)?;
    let _ = fs::remove_file(&socket);
    let listener = UnixListener::bind(&socket).map_err(failed("listen on", &socket))?;
    fs::set_permissions(&socket, Permissions::from_mode(0o600))
        .map_err(failed("keep to its owner", &socket))?;
    let now = Instant::now();
    let shared = Arc::new(Shared {
        kept: Mutex::new(Kept {
            state,
            journal,
            unsynced: false,
            journalled: false,
            synced: now,
            snapshotted: now,
        }),
        connected: AtomicUsize::new(0),
        asked: Mutex::new(now),
        stopping: AtomicBool::new(false),
        watching: Watching::files(
            options.root.clone(),
            options.root.join(WORKSPACE),
            options.debounce,
        ),
        power: Watching::power(),
    });
    let child = started(&options.child, &shared, &socket)?;
    let ticking = {
        let shared = Arc::clone(&shared);
        let options = options.clone();
        let directory = directory.clone();
        let socket = socket.clone();
        let lives_with_child = child.is_some();
        thread::spawn(move || {
            ticked(
                &shared,
                &options,
                &directory,
                &socket,
                lives_with_child,
                euid,
            );
        })
    };

    loop {
        let accepted = listener.accept();

        if shared.stopping.load(Ordering::SeqCst) {
            break;
        }

        let shared = Arc::clone(&shared);
        let root = options.root.clone();
        let socket = socket.clone();
        serving(
            accepted,
            Box::new(move |stream| {
                connected(&stream, &shared, &root, &socket, contract);
            }),
        );
    }

    let _ = ticking.join();
    shared.watching.stop();
    shared.power.stop();

    if let Some(mut process) = child.as_ref().and_then(|held| locked(held).take()) {
        let _ = process.kill();
        let _ = process.wait();
    }

    let _ = snapshotted(&directory, &locked(&shared.kept).state, euid);
    let _ = fs::remove_file(&socket);

    Ok(())
}

/// A directory of the user it runs as, made where it is missing; refused where
/// it is a link, not a directory, or another user's.
fn owned_directory(path: &Path, euid: u32) -> Result<(), String> {
    match fs::symlink_metadata(path) {
        Ok(metadata) => own(path, &metadata, euid, Metadata::is_dir),
        Err(error) if error.kind() == ErrorKind::NotFound => DirBuilder::new()
            .mode(0o700)
            .create(path)
            .map_err(failed("make", path)),
        Err(error) => Err(format!("cannot read {}: {error}", path.display())),
    }
}

/// The socket a daemon of this root answers on: the one in its directory where
/// its path fits a socket's address, else one named by the root's digest in
/// the user's own directory under `/tmp`, which is made, kept to the user and
/// checked as the daemon's own directory is.
///
/// # Errors
///
/// Says why there is none: the short directory cannot be kept to the user.
pub fn socket(root: &Path, euid: u32) -> Result<PathBuf, String> {
    let beside = root.join(DIRECTORY).join(SOCKET);

    if beside.as_os_str().len() <= ADDRESS {
        return Ok(beside);
    }

    let short = PathBuf::from(format!("{SHORT}{euid}"));
    owned_directory(&short, euid)?;
    fs::set_permissions(&short, Permissions::from_mode(0o700))
        .map_err(failed("keep to its owner", &short))?;
    let digest = Sha256::digest(root.as_os_str().as_bytes());
    let named = digest
        .iter()
        .take(NAMED_BY)
        .fold(String::new(), |mut named, byte| {
            let _ = write!(named, "{byte:02x}");
            named
        });

    Ok(short.join(format!("{named}.sock")))
}

/// Whether what a path holds, unfollowed, is of the kind asked and the user's own; why not, otherwise.
fn own(
    path: &Path,
    metadata: &Metadata,
    euid: u32,
    kind: fn(&Metadata) -> bool,
) -> Result<(), String> {
    match () {
        () if metadata.file_type().is_symlink() => Err(format!(
            "{} is a link, which the daemon never follows",
            path.display()
        )),
        () if !kind(metadata) => Err(format!(
            "{} is not what the daemon keeps there",
            path.display()
        )),
        () if metadata.uid() != euid => Err(format!("{} belongs to another user", path.display())),
        () => Ok(()),
    }
}

/// The state the snapshot and then the journal leave; none where neither can be read.
fn read(directory: &Path, euid: u32) -> State {
    let bytes = |name: &str| -> Option<Vec<u8>> {
        let mut file = opened(&directory.join(name), OpenOptions::new().read(true), euid).ok()?;
        let mut bytes = Vec::new();
        file.read_to_end(&mut bytes).ok()?;
        Some(bytes)
    };
    let snapshot = bytes(SNAPSHOT).and_then(|bytes| journal::snapshot_read(&bytes));
    let journalled = bytes(JOURNAL)
        .map(|bytes| journal::journal(&bytes))
        .unwrap_or_default();

    State::of(snapshot.unwrap_or_default().into_iter().chain(journalled))
}

/// A snapshot of the state written to a name no file holds yet, renamed in
/// place of the last one, and the journal it holds emptied.
fn snapshotted(directory: &Path, state: &State, euid: u32) -> Result<(), String> {
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0, |since| since.as_nanos());
    let written = directory.join(format!("{SNAPSHOT}.{}.{nanos}", std::process::id()));
    let mut fresh = OpenOptions::new();
    fresh.write(true).create_new(true).mode(0o600);
    let mut file = opened(&written, &mut fresh, euid)?;
    file.write_all(&journal::snapshot(&state.records()))
        .and_then(|()| file.sync_all())
        .and_then(|()| fs::rename(&written, directory.join(SNAPSHOT)))
        .map_err(|error| {
            let _ = fs::remove_file(&written);
            format!("cannot write the snapshot: {error}")
        })?;

    let journal = directory.join(JOURNAL);

    match opened(&journal, OpenOptions::new().write(true), euid) {
        Ok(file) => file.set_len(0).map_err(failed("empty", &journal)),
        Err(_) => Ok(()),
    }
}

/// A file only its owner reads and writes, made where missing and opened to append, emptied where asked.
fn owned(path: &Path, emptied: bool, euid: u32) -> Result<File, String> {
    let file = opened(
        path,
        OpenOptions::new().create(true).append(true).mode(0o600),
        euid,
    )?;

    if emptied {
        file.set_len(0).map_err(failed("empty", path))?;
    }

    Ok(file)
}

/// A file opened without following a link or waiting on a pipe, where it is a regular file of the user it runs as.
fn opened(path: &Path, options: &mut OpenOptions, euid: u32) -> Result<File, String> {
    let file = options
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(path)
        .map_err(failed("open", path))?;
    let metadata = file.metadata().map_err(failed("read", path))?;
    own(path, &metadata, euid, Metadata::is_file)?;

    Ok(file)
}

/// The child the daemon lives as long as, started; none where it starts none.
fn started(argv: &[String], shared: &Arc<Shared>, socket: &Path) -> Result<Option<Held>, String> {
    let Some((program, arguments)) = argv.split_first() else {
        return Ok(None);
    };
    let child = Command::new(program)
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
        .map_err(|error| format!("cannot start {program}: {error}"))?;
    let held = Arc::new(Mutex::new(Some(child)));
    let watched = Arc::clone(&held);
    let shared = Arc::clone(shared);
    let socket = socket.to_path_buf();

    thread::spawn(move || {
        loop {
            let ended = locked(&watched)
                .as_mut()
                .is_none_or(|child| child.try_wait().ok().flatten().is_some());

            if ended || shared.stopping.load(Ordering::SeqCst) {
                stop(&shared, &socket);
                return;
            }

            thread::sleep(TICK);
        }
    });

    Ok(Some(held))
}

/// Looks at the clocks until the daemon stops: syncs the journal, writes a
/// snapshot while no client is connected, and stops it once idle.
fn ticked(
    shared: &Shared,
    options: &Options,
    directory: &Path,
    socket: &Path,
    lives_with_child: bool,
    euid: u32,
) {
    while !shared.stopping.load(Ordering::SeqCst) {
        thread::sleep(TICK);
        let quiet = shared.connected.load(Ordering::SeqCst) == 0;
        kept_up(&mut locked(&shared.kept), options, quiet, directory, euid);
        let idle = options
            .idle
            .is_some_and(|idle| locked(&shared.asked).elapsed() >= idle);

        if quiet && idle && !lives_with_child {
            stop(shared, socket);
        }
    }
}

/// The journal synced once a sync is due, and a snapshot written once one is
/// due and no client is connected.
fn kept_up(
    kept: &mut MutexGuard<'_, Kept>,
    options: &Options,
    quiet: bool,
    directory: &Path,
    euid: u32,
) {
    if kept.unsynced && kept.synced.elapsed() >= options.sync_every {
        synced(kept);
    }

    if quiet && kept.journalled && kept.snapshotted.elapsed() >= options.snapshot_every {
        synced(kept);

        if snapshotted(directory, &kept.state, euid).is_ok() {
            kept.journalled = false;
            kept.snapshotted = Instant::now();
        }
    }
}

fn synced(kept: &mut MutexGuard<'_, Kept>) {
    if kept
        .journal
        .flush()
        .and_then(|()| kept.journal.sync_data())
        .is_ok()
    {
        kept.unsynced = false;
        kept.synced = Instant::now();
    }
}

/// Serves the connection an accept gave on a thread of its own; nothing where the accept failed.
fn serving(
    accepted: std::io::Result<(UnixStream, SocketAddr)>,
    serve: Box<dyn FnOnce(UnixStream) + Send>,
) {
    if let Ok((stream, _)) = accepted {
        thread::spawn(move || serve(stream));
    }
}

/// Answers each request line of a client until it hangs up or the daemon stops.
fn connected(stream: &UnixStream, shared: &Shared, root: &Path, socket: &Path, contract: u32) {
    shared.connected.fetch_add(1, Ordering::SeqCst);
    let mut writer = stream;
    let mut reader = BufReader::new(stream);
    let mut line = String::new();

    loop {
        line.clear();

        match (&mut reader).take(LONGEST_LINE).read_line(&mut line) {
            Ok(0) | Err(_) => break,
            Ok(_) => {}
        }

        *locked(&shared.asked) = Instant::now();

        if let Some(asked) = protocol::waiting(line.trim_end()) {
            let answer = waited(shared, asked, contract);
            *locked(&shared.asked) = Instant::now();

            if writeln!(writer, "{answer}").is_err() {
                break;
            }

            continue;
        }

        let answer = {
            let mut kept = locked(&shared.kept);
            let answer =
                protocol::answer(&mut kept.state, line.trim_end(), contract, |asked, path| {
                    (Path::new(asked) == root)
                        .then(|| fs::read(root.join(path)).ok())
                        .flatten()
                });
            journalled(&mut kept, &answer.kept);
            answer
        };

        if writeln!(writer, "{}", answer.line).is_err() {
            break;
        }

        if answer.stops {
            stop(shared, socket);
            break;
        }
    }

    shared.connected.fetch_sub(1, Ordering::SeqCst);
}

/// The answer to a client waiting for a change, which counts as no client connected while it waits.
fn waited(shared: &Shared, asked: Result<protocol::Waiting, String>, contract: u32) -> String {
    let asked = match asked {
        Ok(asked) => asked,
        Err(why) => return protocol::refused(&why, contract),
    };
    shared.connected.fetch_sub(1, Ordering::SeqCst);
    let noticed = match &asked.watched {
        protocol::Watched::Files(paths) => shared.watching.changed(paths, asked.since, asked.wait),
        protocol::Watched::Power => shared.power.changed(&[], asked.since, asked.wait),
    };
    shared.connected.fetch_add(1, Ordering::SeqCst);

    match noticed {
        Noticed::Changed(generation) => protocol::changed(generation, contract),
        Noticed::Unwatched(why) => protocol::unwatched(&why, contract),
    }
}

/// The records a request kept, appended to the journal.
fn journalled(kept: &mut MutexGuard<'_, Kept>, records: &[Record]) {
    for record in records {
        if let Some(bytes) = journal::written(record)
            && kept.journal.write_all(&bytes).is_ok()
        {
            kept.unsynced = true;
            kept.journalled = true;
        }
    }
}

/// The daemon told to stop, and its listener woken so it does.
fn stop(shared: &Shared, socket: &Path) {
    if !shared.stopping.swap(true, Ordering::SeqCst) {
        let _ = UnixStream::connect(socket);
    }
}

/// Why a step on a path failed, from the error it failed with.
fn failed<'a>(doing: &'a str, path: &'a Path) -> impl FnOnce(std::io::Error) -> String + 'a {
    move |error| format!("cannot {doing} {}: {error}", path.display())
}

/// Why the daemon's lock was not taken: another daemon holds it, or it could not be tried.
fn unlocked(error: &TryLockError, root: &Path, directory: &Path) -> String {
    match error {
        TryLockError::WouldBlock => format!("a daemon already serves {}", root.display()),
        TryLockError::Error(error) => format!("cannot lock {}: {error}", directory.display()),
    }
}

fn locked<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(PoisonError::into_inner)
}

#[cfg(test)]
mod tests;
