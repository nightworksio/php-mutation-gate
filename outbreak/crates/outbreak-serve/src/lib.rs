//! `outbreak serve` (ADR-0133): a daemon that holds a project's proofs and
//! the names of its static graph's files in memory, keeps them as a snapshot
//! and a journal under `.mutation-gate/outbreak`, watches the files a client
//! names and the machine's power source through the platform's
//! notifications, and answers the gate's PHP on a socket there, one JSON
//! line a request. It serves on Unix alone.

pub mod journal;
#[cfg(unix)]
mod power;
pub mod protocol;
#[cfg(unix)]
pub mod server;
pub mod state;
#[cfg(unix)]
pub mod watching;

use std::path::PathBuf;
use std::time::Duration;

/// How long the changes the daemon watches settle, where its command line does not say.
const DEBOUNCE: Duration = Duration::from_millis(50);

/// How a daemon runs.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Options {
    /// The project's root.
    pub root: PathBuf,
    /// How long it waits for a request before it stops, where it starts no child.
    pub idle: Option<Duration>,
    /// How often, at most, it writes a snapshot while no client is connected.
    pub snapshot_every: Duration,
    /// How often, at most, it syncs the journal to disk.
    pub sync_every: Duration,
    /// How long the changes it watches settle before they count.
    pub debounce: Duration,
    /// The command line of the child it starts and lives as long as; none for no child.
    pub child: Vec<String>,
}

/// How the daemon's command line reads: `<root> [--detach] [--idle <seconds>]
/// [--snapshot-every <seconds>] [--sync-every <seconds>] [--debounce <seconds>]
/// [-- <child> …]`; and
/// whether it is to start in a process of its own.
///
/// # Errors
///
/// Says which argument it cannot read.
pub fn options(arguments: &[String]) -> Result<(Options, bool), String> {
    let (own, child) = match arguments.iter().position(|argument| argument == "--") {
        Some(at) => (arguments.get(..at).unwrap_or_default(), arguments.get(at + 1..).unwrap_or_default()),
        None => (arguments, &[][..]),
    };
    let mut words = own.iter();
    let root = words.next().ok_or("serve takes the project's root")?;
    let mut options = Options {
        root: PathBuf::from(root),
        idle: None,
        snapshot_every: Duration::from_secs(600),
        sync_every: Duration::from_secs(1),
        debounce: DEBOUNCE,
        child: child.to_vec(),
    };
    let mut detach = false;

    while let Some(word) = words.next() {
        match word.as_str() {
            "--detach" => detach = true,
            "--idle" => options.idle = Some(seconds(words.next(), word)?),
            "--snapshot-every" => options.snapshot_every = seconds(words.next(), word)?,
            "--sync-every" => options.sync_every = seconds(words.next(), word)?,
            "--debounce" => options.debounce = seconds(words.next(), word)?,
            other => return Err(format!("serve takes no {other}")),
        }
    }

    Ok((options, detach))
}

fn seconds(value: Option<&String>, option: &str) -> Result<Duration, String> {
    value
        .and_then(|value| value.parse::<f64>().ok())
        .filter(|seconds| seconds.is_finite() && *seconds >= 0.0)
        .map(Duration::from_secs_f64)
        .ok_or_else(|| format!("{option} takes a number of seconds"))
}

#[cfg(test)]
mod tests {
    use std::time::Duration;

    use super::options;

    fn words(line: &str) -> Vec<String> {
        line.split(' ').map(str::to_owned).collect()
    }

    #[test]
    fn reads_the_root_the_clocks_and_the_child() {
        let (read, detach) =
            options(&words("/p --detach --idle 1.5 --snapshot-every 60 --sync-every 0 --debounce 0.02 -- php gate serve")).unwrap();

        assert!(detach);
        assert_eq!(read.idle, Some(Duration::from_millis(1500)));
        assert_eq!(read.snapshot_every, Duration::from_secs(60));
        assert_eq!(read.sync_every, Duration::ZERO);
        assert_eq!(read.debounce, Duration::from_millis(20));
        assert_eq!(read.child, words("php gate serve"));
        assert_eq!(options(&words("/p")).map(|(read, _)| (read.child.len(), read.debounce)), Ok((0, Duration::from_millis(50))));
    }

    #[test]
    fn says_which_argument_it_cannot_read() {
        assert_eq!(options(&[]).unwrap_err(), "serve takes the project's root");
        assert_eq!(options(&words("/p --idle")).unwrap_err(), "--idle takes a number of seconds");
        assert_eq!(options(&words("/p --sync-every -1")).unwrap_err(), "--sync-every takes a number of seconds");
        assert_eq!(options(&words("/p --other")).unwrap_err(), "serve takes no --other");
    }
}
