//! The daemon itself (ADR-0133, decisions 2 to 4): one for a project's root,
//! held by a lock on `.mutation-gate/outbreak/serve.lock`, answering on
//! `.mutation-gate/outbreak/state.sock` in a directory kept to its owner. It
//! reads its state from the snapshot and the journal there, writes a fresh
//! snapshot, journals what it is asked to keep, syncing the journal at most
//! once every `sync_every`, writes a snapshot again while no client is
//! connected at most once every `snapshot_every`, and once more as it stops.
//! It lives as long as the child it starts, where it starts one, or until
//! no request came for `idle`, or it is asked to stop.

use std::fs::{self, DirBuilder, File, OpenOptions, Permissions, TryLockError};
use std::io::{BufRead as _, BufReader, Read as _, Write as _};
use std::os::unix::fs::{DirBuilderExt as _, PermissionsExt as _};
use std::os::unix::net::{UnixListener, UnixStream};
use std::os::unix::process::CommandExt as _;
use std::path::Path;
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, MutexGuard, PoisonError};
use std::thread;
use std::time::{Duration, Instant};

use crate::Options;
use crate::journal::{self, Record};
use crate::protocol;
use crate::state::State;

/// The directory under a project's root the daemon keeps its files in.
pub const DIRECTORY: &str = ".mutation-gate/outbreak";

/// The socket the daemon answers on, in its directory.
pub const SOCKET: &str = "state.sock";

const LOCK: &str = "serve.lock";

const SNAPSHOT: &str = "state.bin";

const JOURNAL: &str = "journal.bin";

/// The longest request line the daemon reads.
const LONGEST_LINE: u64 = 1 << 26;

/// How often the daemon looks at its clocks while it waits.
const TICK: Duration = Duration::from_millis(200);

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
    let directory = options.root.join(DIRECTORY);
    DirBuilder::new()
        .recursive(true)
        .mode(0o700)
        .create(&directory)
        .map_err(|error| format!("cannot make {}: {error}", directory.display()))?;
    fs::set_permissions(&directory, Permissions::from_mode(0o700))
        .map_err(|error| format!("cannot keep {} to its owner: {error}", directory.display()))?;
    let lock = owned(&directory.join(LOCK), false)?;

    match lock.try_lock() {
        Ok(()) => {}
        Err(TryLockError::WouldBlock) => return Err(format!("a daemon already serves {}", options.root.display())),
        Err(TryLockError::Error(error)) => return Err(format!("cannot lock {}: {error}", directory.display())),
    }

    let state = read(&directory);
    snapshotted(&directory, &state)?;
    let journal = owned(&directory.join(JOURNAL), true)?;
    let socket = directory.join(SOCKET);
    let _ = fs::remove_file(&socket);
    let listener = UnixListener::bind(&socket).map_err(|error| format!("cannot listen on {}: {error}", socket.display()))?;
    fs::set_permissions(&socket, Permissions::from_mode(0o600))
        .map_err(|error| format!("cannot keep {} to its owner: {error}", socket.display()))?;
    let now = Instant::now();
    let shared = Arc::new(Shared {
        kept: Mutex::new(Kept { state, journal, unsynced: false, journalled: false, synced: now, snapshotted: now }),
        connected: AtomicUsize::new(0),
        asked: Mutex::new(now),
        stopping: AtomicBool::new(false),
    });
    let child = started(&options.child, &shared, &socket)?;
    let ticking = {
        let shared = Arc::clone(&shared);
        let options = options.clone();
        let directory = directory.clone();
        let socket = socket.clone();
        let lives_with_child = child.is_some();
        thread::spawn(move || ticked(&shared, &options, &directory, &socket, lives_with_child))
    };

    for stream in listener.incoming() {
        if shared.stopping.load(Ordering::SeqCst) {
            break;
        }

        if let Ok(stream) = stream {
            let shared = Arc::clone(&shared);
            let root = options.root.clone();
            let socket = socket.clone();
            thread::spawn(move || connected(stream, &shared, &root, &socket, contract));
        }
    }

    let _ = ticking.join();

    if let Some(mut process) = child.as_ref().and_then(|held| locked(held).take()) {
        let _ = process.kill();
        let _ = process.wait();
    }

    let _ = snapshotted(&directory, &locked(&shared.kept).state);
    let _ = fs::remove_file(&socket);

    Ok(())
}

/// The state the snapshot and then the journal leave; none where neither can be read.
fn read(directory: &Path) -> State {
    let snapshot = fs::read(directory.join(SNAPSHOT)).ok().and_then(|bytes| journal::snapshot_read(&bytes));
    let journalled = fs::read(directory.join(JOURNAL)).map(|bytes| journal::journal(&bytes)).unwrap_or_default();

    State::of(snapshot.unwrap_or_default().into_iter().chain(journalled))
}

/// A snapshot of the state written in place of the last one, at once, and the journal it holds emptied.
fn snapshotted(directory: &Path, state: &State) -> Result<(), String> {
    let written = directory.join(format!("{SNAPSHOT}.new"));
    let mut file = owned(&written, true)?;
    file.write_all(&journal::snapshot(&state.records()))
        .and_then(|()| file.sync_all())
        .and_then(|()| fs::rename(&written, directory.join(SNAPSHOT)))
        .map_err(|error| format!("cannot write the snapshot: {error}"))?;

    match OpenOptions::new().write(true).open(directory.join(JOURNAL)) {
        Ok(journal) => journal.set_len(0).map_err(|error| format!("cannot empty the journal: {error}")),
        Err(_) => Ok(()),
    }
}

/// A file only its owner reads and writes, opened to append, emptied where asked.
fn owned(path: &Path, emptied: bool) -> Result<File, String> {
    use std::os::unix::fs::OpenOptionsExt as _;

    let file = OpenOptions::new()
        .create(true)
        .append(true)
        .mode(0o600)
        .open(path)
        .map_err(|error| format!("cannot open {}: {error}", path.display()))?;

    if emptied {
        file.set_len(0).map_err(|error| format!("cannot empty {}: {error}", path.display()))?;
    }

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
            let ended = locked(&watched).as_mut().is_none_or(|child| child.try_wait().ok().flatten().is_some());

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
fn ticked(shared: &Shared, options: &Options, directory: &Path, socket: &Path, lives_with_child: bool) {
    while !shared.stopping.load(Ordering::SeqCst) {
        thread::sleep(TICK);
        let mut kept = locked(&shared.kept);

        if kept.unsynced && kept.synced.elapsed() >= options.sync_every {
            synced(&mut kept);
        }

        let quiet = shared.connected.load(Ordering::SeqCst) == 0;

        if quiet && kept.journalled && kept.snapshotted.elapsed() >= options.snapshot_every {
            synced(&mut kept);

            if snapshotted(directory, &kept.state).is_ok() {
                kept.journalled = false;
                kept.snapshotted = Instant::now();
            }
        }

        drop(kept);
        let idle = options.idle.is_some_and(|idle| locked(&shared.asked).elapsed() >= idle);

        if quiet && idle && !lives_with_child {
            stop(shared, socket);
        }
    }
}

fn synced(kept: &mut MutexGuard<'_, Kept>) {
    if kept.journal.flush().and_then(|()| kept.journal.sync_data()).is_ok() {
        kept.unsynced = false;
        kept.synced = Instant::now();
    }
}

/// Answers each request line of a client until it hangs up or the daemon stops.
fn connected(stream: UnixStream, shared: &Shared, root: &Path, socket: &Path, contract: u32) {
    shared.connected.fetch_add(1, Ordering::SeqCst);
    let Ok(mut writer) = stream.try_clone() else {
        shared.connected.fetch_sub(1, Ordering::SeqCst);
        return;
    };
    let mut reader = BufReader::new(stream);
    let mut line = String::new();

    loop {
        line.clear();

        match (&mut reader).take(LONGEST_LINE).read_line(&mut line) {
            Ok(0) | Err(_) => break,
            Ok(_) => {}
        }

        *locked(&shared.asked) = Instant::now();
        let answer = {
            let mut kept = locked(&shared.kept);
            let answer = protocol::answer(&mut kept.state, line.trim_end(), contract, |asked, path| {
                (Path::new(asked) == root).then(|| fs::read(root.join(path)).ok()).flatten()
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

fn locked<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(PoisonError::into_inner)
}

#[cfg(test)]
mod tests {
    use std::io::{BufRead as _, BufReader, Write as _};
    use std::os::unix::net::UnixStream;
    use std::path::PathBuf;
    use std::thread;
    use std::time::Duration;

    use super::{DIRECTORY, Options, SOCKET, detached, serve};

    fn options(root: PathBuf) -> Options {
        Options {
            root,
            idle: Some(Duration::from_secs(30)),
            snapshot_every: Duration::from_millis(1),
            sync_every: Duration::from_millis(1),
            child: Vec::new(),
        }
    }

    fn asked(stream: &mut UnixStream, line: &str) -> String {
        writeln!(stream, "{line}").unwrap();
        let mut answer = String::new();
        BufReader::new(stream.try_clone().unwrap()).read_line(&mut answer).unwrap();
        answer.trim_end().to_owned()
    }

    fn connected(root: &std::path::Path) -> UnixStream {
        for _ in 0..200 {
            if let Ok(stream) = UnixStream::connect(root.join(DIRECTORY).join(SOCKET)) {
                return stream;
            }
            thread::sleep(Duration::from_millis(10));
        }
        panic!("the daemon never listened");
    }

    #[test]
    fn keeps_proofs_across_a_restart_and_refuses_a_second_daemon() {
        let root = std::env::temp_dir().join(format!("outbreak-serve-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&root);
        std::fs::create_dir_all(&root).unwrap();
        let first = { let options = options(root.clone()); thread::spawn(move || serve(&options, 1)) };
        let mut stream = connected(&root);

        assert_eq!(asked(&mut stream, r#"keep {"proofs": {"k": "v"}}"#), r#"{"outbreak":1,"kept":1}"#);
        drop(stream);
        thread::sleep(Duration::from_millis(600));
        let journal = std::fs::metadata(root.join(DIRECTORY).join("journal.bin")).unwrap().len();
        let mut stream = connected(&root);

        assert_eq!(journal, 0);
        assert!(serve(&options(root.clone()), 1).is_err_and(|why| why.starts_with("a daemon already serves")));
        assert_eq!(asked(&mut stream, "stop"), r#"{"outbreak":1,"stopping":true}"#);
        assert_eq!(first.join().unwrap(), Ok(()));

        let second = { let options = options(root.clone()); thread::spawn(move || serve(&options, 1)) };
        let mut stream = connected(&root);

        assert_eq!(asked(&mut stream, r#"proofs {"keys": ["k"]}"#), r#"{"outbreak":1,"proofs":{"k":"v"}}"#);
        assert_eq!(asked(&mut stream, "stop"), r#"{"outbreak":1,"stopping":true}"#);
        assert_eq!(second.join().unwrap(), Ok(()));
        std::fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn lives_as_long_as_its_child_or_until_idle() {
        let root = std::env::temp_dir().join(format!("outbreak-serve-child-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&root);
        std::fs::create_dir_all(&root).unwrap();
        let with_child = Options { child: vec!["true".to_owned()], idle: None, ..options(root.clone()) };

        assert_eq!(serve(&with_child, 1), Ok(()));
        assert_eq!(serve(&Options { idle: Some(Duration::from_millis(300)), ..options(root.clone()) }, 1), Ok(()));
        assert!(serve(&Options { child: vec!["/nonexistent/child".to_owned()], ..options(root.clone()) }, 1).is_err());
        std::fs::write(root.join("file"), "").unwrap();
        assert!(serve(&options(root.join("file/inside")), 1).is_err_and(|why| why.starts_with("cannot make")));
        std::fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn starts_a_daemon_in_a_group_of_its_own() {
        assert!(detached(std::path::Path::new("true"), &[]).is_ok());
        assert!(detached(std::path::Path::new("/nonexistent/outbreak"), &[]).is_err_and(|why| why.starts_with("cannot start")));
    }
}
