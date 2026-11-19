use std::io::{BufRead as _, BufReader, Write as _};
use std::os::unix::net::UnixStream;
use std::path::PathBuf;
use std::thread;
use std::time::Duration;

use std::os::unix::fs::symlink;

use super::{
    DIRECTORY, JOURNAL, Kept, Options, SNAPSHOT, detached, journalled, kept_up, own,
    owned_directory, serve, serving, snapshotted, socket, unlocked,
};
use crate::journal::{Kind, Record, written};
use crate::state::State;

fn options(root: PathBuf) -> Options {
    Options {
        root,
        idle: Some(Duration::from_secs(30)),
        snapshot_every: Duration::from_millis(1),
        sync_every: Duration::from_millis(1),
        debounce: Duration::from_millis(10),
        child: Vec::new(),
    }
}

fn asked(stream: &mut UnixStream, line: &str) -> String {
    writeln!(stream, "{line}").unwrap();
    let mut answer = String::new();
    BufReader::new(stream.try_clone().unwrap())
        .read_line(&mut answer)
        .unwrap();
    answer.trim_end().to_owned()
}

fn connected(root: &std::path::Path) -> UnixStream {
    let at = socket(root, rustix::process::geteuid().as_raw()).unwrap();
    let since = std::time::Instant::now();

    loop {
        if let Ok(stream) = UnixStream::connect(&at) {
            return stream;
        }

        assert!(
            since.elapsed() < Duration::from_secs(5),
            "the daemon never listened"
        );
        thread::sleep(Duration::from_millis(10));
    }
}

#[test]
fn serves_nothing_where_an_accept_failed() {
    serving(Err(std::io::Error::other("refused")), Box::new(drop));
}

#[test]
fn keeps_proofs_across_a_restart_and_refuses_a_second_daemon() {
    let root = std::env::temp_dir().join(format!("outbreak-serve-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(&root).unwrap();
    let first = {
        let options = options(root.clone());
        thread::spawn(move || serve(&options, 1))
    };
    let mut stream = connected(&root);

    assert_eq!(
        asked(&mut stream, r#"keep {"proofs": {"k": "v"}}"#),
        r#"{"outbreak":1,"kept":1}"#
    );
    drop(stream);
    let journal = root.join(DIRECTORY).join("journal.bin");
    let since = std::time::Instant::now();

    while std::fs::metadata(&journal).unwrap().len() != 0 {
        assert!(
            since.elapsed() < Duration::from_secs(5),
            "the daemon never wrote its snapshot once the client left"
        );
        thread::sleep(Duration::from_millis(10));
    }

    let mut stream = connected(&root);

    assert!(
        serve(&options(root.clone()), 1)
            .is_err_and(|why| why.starts_with("a daemon already serves"))
    );
    assert_eq!(
        asked(&mut stream, "stop"),
        r#"{"outbreak":1,"stopping":true}"#
    );
    assert_eq!(first.join().unwrap(), Ok(()));

    let second = {
        let options = options(root.clone());
        thread::spawn(move || serve(&options, 1))
    };
    let mut stream = connected(&root);

    assert_eq!(
        asked(&mut stream, r#"proofs {"keys": ["k"]}"#),
        r#"{"outbreak":1,"proofs":{"k":"v"}}"#
    );
    assert_eq!(
        asked(&mut stream, "stop"),
        r#"{"outbreak":1,"stopping":true}"#
    );
    assert_eq!(second.join().unwrap(), Ok(()));
    std::fs::remove_dir_all(&root).unwrap();
}

#[test]
fn answers_a_client_waiting_for_a_change_of_the_files() {
    let root = std::env::temp_dir().join(format!("outbreak-serve-watch-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(root.join("src")).unwrap();
    let serving = {
        let options = options(root.clone());
        thread::spawn(move || serve(&options, 1))
    };
    let mut stream = connected(&root);

    assert_eq!(
        asked(
            &mut stream,
            r#"changed {"paths": ["src"], "since": 0, "wait": 0}"#
        ),
        r#"{"outbreak":1,"changed":0}"#
    );
    let writing = {
        let file = root.join("src/A.php");
        thread::spawn(move || {
            thread::sleep(Duration::from_millis(100));
            std::fs::write(file, "<?php\n")
        })
    };
    let after = asked(
        &mut stream,
        r#"changed {"paths": ["src"], "since": 0, "wait": 5000}"#,
    );
    writing.join().unwrap().unwrap();

    assert_ne!(after, r#"{"outbreak":1,"changed":0}"#);
    assert!(after.starts_with(r#"{"outbreak":1,"changed":"#), "{after}");
    assert!(
        asked(
            &mut stream,
            r#"changed {"paths": ["/"], "since": 0, "wait": 0}"#
        )
        .contains(r#""unwatched":"#)
    );
    assert!(asked(&mut stream, r#"changed {"since": 0}"#).contains(r#""refused":"#));
    assert_eq!(
        asked(&mut stream, "stop"),
        r#"{"outbreak":1,"stopping":true}"#
    );
    assert_eq!(serving.join().unwrap(), Ok(()));
    std::fs::remove_dir_all(&root).unwrap();
}

#[test]
fn lives_as_long_as_its_child_or_until_idle() {
    let root = std::env::temp_dir().join(format!("outbreak-serve-child-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(&root).unwrap();
    let with_child = Options {
        child: vec!["true".to_owned()],
        idle: None,
        ..options(root.clone())
    };

    assert_eq!(serve(&with_child, 1), Ok(()));
    assert_eq!(
        serve(
            &Options {
                idle: Some(Duration::from_millis(300)),
                ..options(root.clone())
            },
            1
        ),
        Ok(())
    );
    assert!(
        serve(
            &Options {
                child: vec!["/nonexistent/child".to_owned()],
                ..options(root.clone())
            },
            1
        )
        .is_err()
    );
    std::fs::write(root.join("file"), "").unwrap();
    assert!(
        serve(&options(root.join("file/inside")), 1).is_err_and(|why| why.starts_with("cannot"))
    );
    std::fs::remove_dir_all(&root).unwrap();
}

#[test]
fn starts_a_daemon_in_a_group_of_its_own() {
    assert!(detached(std::path::Path::new("true"), &[]).is_ok());
    assert!(
        detached(std::path::Path::new("/nonexistent/outbreak"), &[])
            .is_err_and(|why| why.starts_with("cannot start"))
    );
}

fn fresh(name: &str) -> PathBuf {
    let root = std::env::temp_dir().join(format!("outbreak-serve-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(&root).unwrap();
    root
}

#[test]
fn refuses_a_linked_workspace_or_directory() {
    let root = fresh("linked");
    let elsewhere = fresh("elsewhere");
    symlink(&elsewhere, root.join(".mutation-gate")).unwrap();

    assert!(
        serve(&options(root.clone()), 1)
            .is_err_and(|why| why.ends_with("is a link, which the daemon never follows"))
    );

    std::fs::remove_file(root.join(".mutation-gate")).unwrap();
    std::fs::create_dir(root.join(".mutation-gate")).unwrap();
    symlink(&elsewhere, root.join(DIRECTORY)).unwrap();

    assert!(
        serve(&options(root.clone()), 1)
            .is_err_and(|why| why.ends_with("is a link, which the daemon never follows"))
    );
    assert_eq!(std::fs::read_dir(&elsewhere).unwrap().count(), 0);
    std::fs::remove_dir_all(&root).unwrap();
    std::fs::remove_dir_all(&elsewhere).unwrap();
}

#[test]
fn follows_no_link_to_its_journal_or_snapshot() {
    let root = fresh("files");
    let outside = fresh("outside").join("victim");
    std::fs::write(&outside, "kept").unwrap();
    std::fs::create_dir_all(root.join(DIRECTORY)).unwrap();
    symlink(&outside, root.join(DIRECTORY).join("journal.bin")).unwrap();
    symlink(&outside, root.join(DIRECTORY).join("state.bin")).unwrap();

    assert!(serve(&options(root.clone()), 1).is_err_and(|why| why.starts_with("cannot open")));
    assert_eq!(std::fs::read_to_string(&outside).unwrap(), "kept");
    assert!(
        !std::fs::symlink_metadata(root.join(DIRECTORY).join("state.bin"))
            .unwrap()
            .file_type()
            .is_symlink()
    );
    std::fs::remove_dir_all(&root).unwrap();
    std::fs::remove_dir_all(outside.parent().unwrap()).unwrap();
}

#[test]
fn refuses_what_is_not_its_own_kind_or_its_user_s() {
    let root = fresh("owner");
    let euid = rustix::process::geteuid().as_raw();
    let directory = std::fs::symlink_metadata(&root).unwrap();

    assert!(
        owned_directory(&root, euid + 1).is_err_and(|why| why.ends_with("belongs to another user"))
    );
    assert!(
        own(&root, &directory, euid, std::fs::Metadata::is_file)
            .is_err_and(|why| why.ends_with("is not what the daemon keeps there"))
    );
    assert_eq!(owned_directory(&root, euid), Ok(()));

    let file = root.join("file");
    std::fs::write(&file, "").unwrap();

    assert!(
        owned_directory(&file.join("under"), euid).is_err_and(|why| why.starts_with("cannot read"))
    );
    std::fs::remove_dir_all(&root).unwrap();
}

/// What a daemon keeps in this directory, its journal empty, synced and snapshotted now.
fn kept_in(directory: &std::path::Path) -> std::sync::Mutex<Kept> {
    let now = std::time::Instant::now();

    std::sync::Mutex::new(Kept {
        state: State::default(),
        journal: std::fs::File::create(directory.join(JOURNAL)).unwrap(),
        unsynced: false,
        journalled: false,
        synced: now,
        snapshotted: now,
    })
}

#[test]
fn appends_what_a_request_kept_to_the_journal_and_syncs_and_snapshots_once_each_is_due() {
    let directory = fresh("kept");
    let euid = rustix::process::geteuid().as_raw();
    let kept = kept_in(&directory);
    let mut kept = kept.lock().unwrap();
    let record = Record {
        kind: Kind::Proof,
        key: String::from("k"),
        value: b"v".to_vec(),
    };
    let every = |sync: u64, snapshot: u64| Options {
        sync_every: Duration::from_secs(sync),
        snapshot_every: Duration::from_secs(snapshot),
        ..options(directory.clone())
    };
    let synced = kept.synced;
    kept_up(&mut kept, &every(0, 0), true, &directory, euid);

    assert_eq!(kept.synced, synced);
    assert_eq!(kept.snapshotted, synced);

    journalled(&mut kept, std::slice::from_ref(&record));

    assert!(kept.unsynced && kept.journalled);
    assert_eq!(
        std::fs::read(directory.join(JOURNAL)).unwrap(),
        written(&record).unwrap()
    );

    kept_up(&mut kept, &every(7200, 7200), true, &directory, euid);

    assert!(kept.unsynced && kept.journalled);

    kept_up(&mut kept, &every(0, 7200), false, &directory, euid);

    assert!(!kept.unsynced && kept.journalled);

    kept_up(&mut kept, &every(7200, 0), false, &directory, euid);

    assert!(kept.journalled);
    assert!(!directory.join(SNAPSHOT).exists());

    kept_up(&mut kept, &every(7200, 0), true, &directory, euid);

    assert!(!kept.journalled);
    assert!(directory.join(SNAPSHOT).exists());
    std::fs::remove_dir_all(&directory).unwrap();
}

#[test]
fn answers_beside_its_files_where_the_path_fits_and_in_the_users_short_directory_otherwise() {
    let euid = rustix::process::geteuid().as_raw();
    let short = std::path::Path::new("/srv/app");
    let long = std::path::PathBuf::from(format!("/srv/{}", "a".repeat(120)));
    let moved = socket(&long, euid).unwrap();
    let name = moved.file_name().unwrap().to_string_lossy().into_owned();

    assert_eq!(
        socket(short, euid).unwrap(),
        short.join(DIRECTORY).join("state.sock")
    );
    assert_eq!(
        moved.parent().unwrap(),
        std::path::Path::new(&format!("/tmp/mutation-gate-{euid}"))
    );
    assert_eq!(name.len(), "0123456789abcdef.sock".len());
    assert_eq!(moved.extension(), Some(std::ffi::OsStr::new("sock")));
    assert_ne!(
        socket(&long.join("b"), euid).unwrap(),
        moved,
        "each root has a socket of its own"
    );
    assert_eq!(
        std::os::unix::fs::PermissionsExt::mode(
            &std::fs::metadata(moved.parent().unwrap())
                .unwrap()
                .permissions()
        ) & 0o777,
        0o700
    );
}

#[test]
fn answers_the_graph_from_the_disk_and_the_power_source_and_lets_a_client_hang_up() {
    let root = fresh("graph");
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(root.join("src/A.php"), "<?php final class A {}").unwrap();
    let serving = {
        let options = options(root.clone());
        thread::spawn(move || serve(&options, 1))
    };
    let graph = |at: &std::path::Path| {
        format!(
            r#"graph {{"root": {:?}, "sources": ["src/A.php"], "tests": [], "reach": [], "names": ["src/A.php"]}}"#,
            at.display().to_string()
        )
    };
    let mut stream = connected(&root);

    assert!(!asked(&mut stream, &graph(&root)).contains("a file that cannot be read"));
    assert!(
        asked(&mut stream, &graph(std::path::Path::new("/elsewhere")))
            .contains("a file that cannot be read")
    );
    assert!(
        asked(&mut stream, r#"power {"since": 0, "wait": 0}"#).starts_with(r#"{"outbreak":1,""#)
    );

    for line in [
        "contract",
        r#"changed {"paths": ["src"], "since": 0, "wait": 0}"#,
    ] {
        let mut leaving = connected(&root);
        writeln!(leaving, "{line}").unwrap();
        drop(leaving);
    }

    thread::sleep(Duration::from_millis(200));

    assert_eq!(
        asked(&mut stream, "stop"),
        r#"{"outbreak":1,"stopping":true}"#
    );
    assert_eq!(serving.join().unwrap(), Ok(()));
    std::fs::remove_dir_all(&root).unwrap();
}

#[test]
fn leaves_no_snapshot_behind_that_it_could_not_put_in_place() {
    let directory = fresh("snapshot");
    std::fs::create_dir_all(directory.join(SNAPSHOT).join("held")).unwrap();
    let euid = rustix::process::geteuid().as_raw();

    assert!(
        snapshotted(&directory, &State::default(), euid)
            .is_err_and(|why| why.starts_with("cannot write the snapshot"))
    );
    assert_eq!(std::fs::read_dir(&directory).unwrap().count(), 1);
    std::fs::remove_dir_all(&directory).unwrap();
}

#[test]
fn says_why_its_lock_was_not_taken() {
    let (root, directory) = (std::path::Path::new("/p"), std::path::Path::new("/p/d"));

    assert_eq!(
        unlocked(&std::fs::TryLockError::WouldBlock, root, directory),
        "a daemon already serves /p"
    );
    assert_eq!(
        unlocked(
            &std::fs::TryLockError::Error(std::io::Error::other("gone")),
            root,
            directory
        ),
        "cannot lock /p/d: gone"
    );
}
