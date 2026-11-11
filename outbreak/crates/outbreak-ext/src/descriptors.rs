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
    let mut held = Held::default();
    let Ok(listed) = fs::read_dir(LISTED) else {
        return held;
    };

    for entry in listed.flatten() {
        let Some(descriptor) = entry
            .file_name()
            .to_str()
            .and_then(|name| name.parse::<i32>().ok())
        else {
            continue;
        };

        if descriptor >= FIRST {
            count(&mut held, descriptor, ignored);
        }
    }

    held
}

fn count(held: &mut Held, descriptor: i32, ignored: &Ignored) {
    // SAFETY: F_GETFL reads a descriptor's flags; a closed one answers -1.
    let flags = unsafe { libc::fcntl(descriptor, libc::F_GETFL) };

    if flags < 0 {
        return;
    }

    // SAFETY: an all-zero stat is a valid value for fstat to fill.
    let mut status: libc::stat = unsafe { std::mem::zeroed() };

    // SAFETY: the descriptor is open, and `status` is ours to fill.
    if unsafe { libc::fstat(descriptor, &raw mut status) } != 0 {
        return;
    }

    let writable = flags & libc::O_ACCMODE != libc::O_RDONLY;

    match status.st_mode & libc::S_IFMT {
        libc::S_IFSOCK => held.sockets += 1,
        libc::S_IFREG
            if writable && !ignored.holds(identity(status.st_dev), identity(status.st_ino)) =>
        {
            held.files += 1;
        }
        libc::S_IFIFO if writable => held.pipes += 1,
        _ => {}
    }
}

fn identity<T: TryInto<i64>>(number: T) -> i64 {
    number.try_into().unwrap_or(i64::MAX)
}

#[cfg(test)]
mod tests {
    use super::Ignored;

    #[test]
    fn holds_each_pair_given_and_skips_the_malformed() {
        let ignored = Ignored::of(&[vec![1, 2], vec![3], vec![4, 5, 6]]);

        assert!(ignored.holds(1, 2));
        assert!(!ignored.holds(2, 1));
        assert!(!ignored.holds(4, 5));
    }
}
