//! The notifications of the machine's power source the daemon watches
//! (ADR-0133, decision 8), so a client reads where the machine draws its
//! power from again only once it changed: on macOS notifyd's power source and
//! Low Power Mode keys, through `notifyutil`; on Linux the kernel's
//! `power_supply` events and the ACPI platform profile's changes.

use crate::watching::Notices;

#[cfg(target_os = "macos")]
pub(crate) fn source() -> Result<Box<dyn Notices>, String> {
    notifyd::Notifyd::started().map(|notices| Box::new(notices) as Box<dyn Notices>)
}

#[cfg(target_os = "linux")]
pub(crate) fn source() -> Result<Box<dyn Notices>, String> {
    uevents::Uevents::bound().map(|notices| Box::new(notices) as Box<dyn Notices>)
}

#[cfg(not(any(target_os = "macos", target_os = "linux")))]
pub(crate) fn source() -> Result<Box<dyn Notices>, String> {
    Err(String::from("this platform tells the daemon of no change of the power source"))
}

#[cfg(target_os = "macos")]
mod notifyd {
    use std::io::{BufRead as _, BufReader};
    use std::process::{Child, Command, Stdio};
    use std::sync::mpsc::{self, Receiver, RecvTimeoutError};
    use std::thread;
    use std::time::Duration;

    use crate::watching::Notices;

    /// The program that reports notifyd's notifications, one line each.
    const NOTIFYUTIL: &str = "/usr/bin/notifyutil";

    /// The keys notifyd posts as the power source changes and as Low Power Mode turns on or off.
    const KEYS: [&str; 2] = ["com.apple.system.powersources.source", "com.apple.system.lowpowermode"];

    /// notifyd's notifications of the power source, as `notifyutil` reports them.
    pub(super) struct Notifyd {
        child: Child,
        lines: Receiver<()>,
    }

    impl Notifyd {
        pub(super) fn started() -> Result<Self, String> {
            let mut child = Command::new(NOTIFYUTIL)
                .args(KEYS.iter().flat_map(|key| ["-w", *key]))
                .stdin(Stdio::null())
                .stdout(Stdio::piped())
                .stderr(Stdio::null())
                .spawn()
                .map_err(|error| format!("cannot start {NOTIFYUTIL}: {error}"))?;
            let output = child.stdout.take().ok_or_else(|| format!("{NOTIFYUTIL} has no output"))?;
            let (told, lines) = mpsc::channel();

            thread::spawn(move || {
                for line in BufReader::new(output).lines() {
                    if line.is_err() || told.send(()).is_err() {
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
}

#[cfg(target_os = "linux")]
mod uevents {
    use std::fs::File;
    use std::io::{Read as _, Seek as _, SeekFrom};
    use std::os::fd::OwnedFd;
    use std::time::Duration;

    use rustix::event::{PollFd, PollFlags, Timespec, poll};
    use rustix::io::Errno;
    use rustix::net::{AddressFamily, RecvFlags, SocketFlags, SocketType, bind, netlink, recv, socket_with};

    use crate::watching::Notices;

    /// The multicast group the kernel sends its device events to.
    const KERNEL_GROUP: u32 = 1;

    /// What a device event of a power supply holds.
    const POWER_SUPPLY: &[u8] = b"SUBSYSTEM=power_supply";

    /// The ACPI platform profile, which the kernel notifies a change of.
    const PROFILE: &str = "/sys/firmware/acpi/platform_profile";

    /// The bytes of one device event read at once.
    const BUFFER: usize = 1 << 13;

    /// The kernel's device events of power supplies, and the platform profile's changes where it has one.
    pub(super) struct Uevents {
        socket: OwnedFd,
        profile: Option<File>,
        buffer: Vec<u8>,
    }

    impl Uevents {
        pub(super) fn bound() -> Result<Self, String> {
            let socket = socket_with(
                AddressFamily::NETLINK,
                SocketType::DGRAM,
                SocketFlags::CLOEXEC | SocketFlags::NONBLOCK,
                Some(netlink::KOBJECT_UEVENT),
            )
            .map_err(|error| format!("cannot open the kernel's device events: {error}"))?;
            bind(&socket, &netlink::SocketAddrNetlink::new(0, KERNEL_GROUP))
                .map_err(|error| format!("cannot listen to the kernel's device events: {error}"))?;
            let mut profile = File::open(PROFILE).ok();

            if let Some(file) = profile.as_mut() {
                drained(file);
            }

            Ok(Self { socket, profile, buffer: vec![0; BUFFER] })
        }
    }

    impl Notices for Uevents {
        fn next(&mut self, within: Duration) -> Result<bool, String> {
            let timeout = Timespec::try_from(within).map_err(|error| format!("cannot wait {within:?}: {error}"))?;
            let (events, profiled) = {
                let mut fds = vec![PollFd::new(&self.socket, PollFlags::IN)];
                fds.extend(self.profile.as_ref().map(|file| PollFd::new(file, PollFlags::PRI | PollFlags::ERR)));

                match poll(&mut fds, Some(&timeout)) {
                    Ok(0) | Err(Errno::INTR) => return Ok(false),
                    Ok(_) => {}
                    Err(error) => return Err(format!("cannot wait for the kernel's device events: {error}")),
                }

                let ready = |at: usize| fds.get(at).is_some_and(|fd| !fd.revents().is_empty());
                (ready(0), ready(1))
            };
            let mut changed = false;

            if events {
                loop {
                    match recv(&self.socket, &mut self.buffer[..], RecvFlags::empty()) {
                        Ok((read, _)) => {
                            changed |= self.buffer.get(..read).is_some_and(|event| {
                                event.windows(POWER_SUPPLY.len()).any(|part| part == POWER_SUPPLY)
                            });
                        }
                        Err(Errno::AGAIN | Errno::INTR) => break,
                        Err(error) => return Err(format!("cannot read the kernel's device events: {error}")),
                    }
                }
            }

            if profiled && let Some(file) = self.profile.as_mut() {
                drained(file);
                changed = true;
            }

            Ok(changed)
        }
    }

    /// The profile read from its start, as the kernel asks before it notifies the next change.
    fn drained(file: &mut File) {
        let mut text = String::new();
        let _ = file.seek(SeekFrom::Start(0)).and_then(|_| file.read_to_string(&mut text));
    }
}
