//! The descriptors a process holds that a forked child would share with it:
//! each regular file and pipe open for writing, and each socket, past the
//! three standard streams. A child that writes through one moves the
//! parent's offset, or writes into what the parent reads, so a split stream
//! forks no test that holds one more than the stream held when it began.

#![allow(unsafe_code, reason = "a descriptor's mode and kind are read with fcntl and fstat")]

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

/// What the process holds now; nothing where the system lists no descriptors.
#[must_use]
pub fn held() -> Held {
    let mut held = Held::default();
    let Ok(listed) = fs::read_dir(LISTED) else {
        return held;
    };

    for entry in listed.flatten() {
        let Some(descriptor) = entry.file_name().to_str().and_then(|name| name.parse::<i32>().ok()) else {
            continue;
        };

        if descriptor >= FIRST {
            count(&mut held, descriptor);
        }
    }

    held
}

fn count(held: &mut Held, descriptor: i32) {
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
        libc::S_IFREG if writable => held.files += 1,
        libc::S_IFIFO if writable => held.pipes += 1,
        _ => {}
    }
}
