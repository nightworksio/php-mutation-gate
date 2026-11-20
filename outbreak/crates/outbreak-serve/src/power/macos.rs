//! The power source's notifications on macOS: notifyd's keys, as `notifyutil` reports them.

use std::io::{BufRead as _, BufReader};
use std::process::{Child, Command, Stdio};
use std::sync::mpsc::{self, Receiver, RecvTimeoutError};
use std::thread;
use std::time::Duration;

use crate::watching::Notices;

/// The program that reports notifyd's notifications, one line each.
const NOTIFYUTIL: &str = "/usr/bin/notifyutil";

/// The keys notifyd posts as the power source changes and as Low Power Mode turns on or off.
const KEYS: [&str; 2] = [
    "com.apple.system.powersources.source",
    "com.apple.system.lowpowermode",
];

/// notifyd's notifications of the power source, as `notifyutil` reports them.
pub(super) struct Notifyd {
    child: Child,
    lines: Receiver<()>,
}

impl Notifyd {
    pub(super) fn started() -> Result<Self, String> {
        Self::of(&KEYS)
    }

    /// The notifications of these keys.
    fn of(keys: &[&str]) -> Result<Self, String> {
        let mut child = Command::new(NOTIFYUTIL)
            .args(keys.iter().flat_map(|key| ["-w", *key]))
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::null())
            .spawn()
            .map_err(|error| format!("cannot start {NOTIFYUTIL}: {error}"))?;
        let output = child
            .stdout
            .take()
            .ok_or_else(|| format!("{NOTIFYUTIL} has no output"))?;
        let (told, lines) = mpsc::channel();

        thread::spawn(move || {
            for _ in BufReader::new(output).lines().map_while(Result::ok) {
                if told.send(()).is_err() {
                    return;
                }
            }
        });

        Ok(Self { child, lines })
    }
}

impl Notices for Notifyd {
    fn next(&mut self, within: Duration) -> Result<bool, String> {
        match self.lines.recv_timeout(within) {
            Ok(()) => {
                while self.lines.try_recv().is_ok() {}
                Ok(true)
            }
            Err(RecvTimeoutError::Timeout) => Ok(false),
            Err(RecvTimeoutError::Disconnected) => Err(format!("{NOTIFYUTIL} stopped")),
        }
    }
}

impl Drop for Notifyd {
    fn drop(&mut self) {
        let _ = self.child.kill();
        let _ = self.child.wait();
    }
}

pub(crate) fn source() -> Result<Box<dyn Notices>, String> {
    Notifyd::started().map(|notices| Box::new(notices) as Box<dyn Notices>)
}

#[cfg(test)]
mod tests {
    use std::process::Command;
    use std::time::Duration;

    use rustix::process::{Pid, test_kill_process};

    use super::{NOTIFYUTIL, Notifyd};
    use crate::watching::Notices;

    #[test]
    fn tells_of_a_posted_key_and_stops_its_reporter_once_dropped() {
        let key = format!("io.nightworks.outbreak.test.{}", std::process::id());
        let mut notices = Notifyd::of(&[&key]).unwrap();

        assert_eq!(notices.next(Duration::from_millis(100)), Ok(false));

        let told = (0..15).any(|_| {
            Command::new(NOTIFYUTIL)
                .args(["-p", &key])
                .status()
                .is_ok_and(|status| status.success())
                && notices.next(Duration::from_millis(200)) == Ok(true)
        });
        let pid = Pid::from_child(&notices.child);
        drop(notices);

        assert!(told);
        assert!(test_kill_process(pid).is_err());
    }
}
