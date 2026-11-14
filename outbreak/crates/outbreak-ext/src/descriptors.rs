//! The descriptors a process holds that a forked child would share with it:
//! each regular file and pipe open for writing, and each socket, past the
//! three standard streams. A child that writes through one moves the
//! parent's offset, or writes into what the parent reads, so a split stream
//! forks no test that holds one more than the stream held when it began.
//! The files the test runner writes its own output through are left out,
//! known by device and inode.

#![allow(
    unsafe_code,
    reason = "a descriptor's mode and kind are read with fcntl and fstat"
)]

use std::fs;

/// Where the system lists the process's own descriptors.
const LISTED: &str = "/dev/fd";

/// The first descriptor past standard input, output and error.
const FIRST: i32 = 3;

/// How many descriptors of each kind the process holds that a child would share.
#[derive(Debug, Default, Clone, Copy, PartialEq, Eq)]
pub struct Held {
    /// Regular files open for writing.
    pub files: i64,
    /// Pipes open for writing.
    pub pipes: i64,
    /// Sockets, open either way.
    pub sockets: i64,
}

/// The regular files left uncounted, each by its device and inode.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct Ignored {
    files: Vec<(i64, i64)>,
}

impl Ignored {
    /// The files given as pairs of device and inode; a pair of any other length is skipped.
    #[must_use]
    pub fn of(pairs: &[Vec<i64>]) -> Self {
        let files = pairs
            .iter()
            .filter_map(|pair| match pair.as_slice() {
                [device, inode] => Some((*device, *inode)),
                _ => None,
            })
            .collect();

        Self { files }
    }

    /// Whether a file of this device and inode is left uncounted.
    #[must_use]
    pub fn holds(&self, device: i64, inode: i64) -> bool {
        self.files.contains(&(device, inode))
    }
}

/// What the process holds now; nothing where the system lists no descriptors.
#[must_use]
pub fn held(ignored: &Ignored) -> Held {
    fs::read_dir(LISTED)
        .into_iter()
        .flatten()
        .flatten()
        .filter_map(|entry| entry.file_name().to_str()?.parse::<i32>().ok())
        .filter(|&descriptor| descriptor >= FIRST)
        .filter_map(status)
        .fold(Held::default(), |held, status| held.with(status, ignored))
}

impl Held {
    /// These counts, with a descriptor of this status counted where a child would share it.
    fn with(mut self, status: Status, ignored: &Ignored) -> Self {
        match status.kind {
            libc::S_IFSOCK => self.sockets += 1,
            libc::S_IFREG if status.writable && !ignored.holds(status.device, status.inode) => {
                self.files += 1;
            }
            libc::S_IFIFO if status.writable => self.pipes += 1,
            _ => {}
        }

        self
    }
}

/// A descriptor's access, kind and identity, as fcntl and fstat read them.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
struct Status {
    writable: bool,
    kind: libc::mode_t,
    device: i64,
    inode: i64,
}

/// An open descriptor's status; none where it is closed.
fn status(descriptor: i32) -> Option<Status> {
    // SAFETY: F_GETFL reads a descriptor's flags; a closed one answers -1.
    let flags = unsafe { libc::fcntl(descriptor, libc::F_GETFL) };
    // SAFETY: an all-zero stat is a valid value for fstat to fill.
    let mut status: libc::stat = unsafe { std::mem::zeroed() };
    // SAFETY: the descriptor is open where its flags were read, and `status` is ours to fill.
    let read = flags >= 0 && unsafe { libc::fstat(descriptor, &raw mut status) } == 0;

    read.then(|| Status {
        writable: flags & libc::O_ACCMODE != libc::O_RDONLY,
        kind: status.st_mode & libc::S_IFMT,
        device: identity(status.st_dev),
        inode: identity(status.st_ino),
    })
}

fn identity<T: TryInto<i64>>(number: T) -> i64 {
    number.try_into().unwrap_or(i64::MAX)
}

#[cfg(test)]
mod tests {
    use std::os::unix::fs::MetadataExt as _;
    use std::os::unix::net::UnixStream;

    use super::{FIRST, Held, Ignored, Status, held, identity, status};

    #[test]
    fn holds_each_pair_given_and_skips_the_malformed() {
        let ignored = Ignored::of(&[vec![1, 2], vec![3], vec![4, 5, 6]]);

        assert!(ignored.holds(1, 2));
        assert!(!ignored.holds(2, 1));
        assert!(!ignored.holds(4, 5));
    }

    fn of(kind: libc::mode_t, writable: bool) -> Status {
        Status {
            writable,
            kind,
            device: 1,
            inode: 2,
        }
    }

    #[test]
    fn counts_what_a_child_would_share() {
        let none = Ignored::default();
        let counted = [
            of(libc::S_IFSOCK, false),
            of(libc::S_IFREG, true),
            of(libc::S_IFREG, false),
            of(libc::S_IFIFO, true),
            of(libc::S_IFIFO, false),
            of(libc::S_IFDIR, true),
        ]
        .into_iter()
        .fold(Held::default(), |held, status| held.with(status, &none));

        assert_eq!(
            counted,
            Held {
                files: 1,
                pipes: 1,
                sockets: 1,
            }
        );
        assert_eq!(
            Held::default().with(of(libc::S_IFREG, true), &Ignored::of(&[vec![1, 2]])),
            Held::default()
        );
    }

    #[test]
    fn reads_no_status_of_a_closed_descriptor() {
        assert_eq!(status(-1), None);
        assert_eq!(identity(u64::MAX), i64::MAX);
        assert!(status(FIRST - 1).is_some());
    }

    #[test]
    fn counts_the_descriptors_the_process_holds() {
        let path =
            std::env::temp_dir().join(format!("outbreak-descriptors-{}", std::process::id()));
        let before = held(&Ignored::default());
        let file = std::fs::File::create(&path).unwrap();
        let (reader, writer) = std::io::pipe().unwrap();
        let sockets = UnixStream::pair().unwrap();
        let after = held(&Ignored::default());
        let metadata = file.metadata().unwrap();
        let ignored = Ignored::of(&[vec![identity(metadata.dev()), identity(metadata.ino())]]);

        assert_eq!(after.files - before.files, 1);
        assert_eq!(after.pipes - before.pipes, 1);
        assert_eq!(after.sockets - before.sockets, 2);
        assert_eq!(held(&ignored).files, before.files);

        drop((file, reader, writer, sockets));
        std::fs::remove_file(path).unwrap();
    }
}
