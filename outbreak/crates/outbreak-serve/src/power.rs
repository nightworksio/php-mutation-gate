//! The notifications of the machine's power source the daemon watches
//! (ADR-0133, decision 8), so a client reads where the machine draws its
//! power from again only once it changed: on macOS notifyd's power source and
//! Low Power Mode keys, through `notifyutil`; on Linux the kernel's
//! `power_supply` events and the ACPI platform profile's changes.

#[cfg(not(any(target_os = "macos", target_os = "linux")))]
mod elsewhere;
#[cfg(target_os = "macos")]
mod macos;

#[cfg(not(any(target_os = "macos", target_os = "linux")))]
pub(crate) use elsewhere::source;
#[cfg(target_os = "macos")]
pub(crate) use macos::source;

#[cfg(target_os = "linux")]
use crate::watching::Notices;

#[cfg(target_os = "linux")]
pub(crate) fn source() -> Result<Box<dyn Notices>, String> {
    uevents::Uevents::bound().map(|notices| Box::new(notices) as Box<dyn Notices>)
}

#[cfg(target_os = "linux")]
mod uevents {
    use std::fs::File;
    use std::io::{Read as _, Seek as _, SeekFrom};
    use std::os::fd::OwnedFd;
    use std::time::Duration;

    use rustix::event::{PollFd, PollFlags, Timespec, poll};
    use rustix::net::{
        AddressFamily, RecvFlags, SocketFlags, SocketType, bind, netlink, recv, socket_with,
    };

    use crate::watching::{Notices, polled, taken, tried};

    /// The multicast group the kernel sends its device events to.
    const KERNEL_GROUP: u32 = 1;

    /// What a device event of a power supply holds.
    const POWER_SUPPLY: &[u8] = b"SUBSYSTEM=power_supply";

    /// The ACPI platform profile, which the kernel notifies a change of.
    const PROFILE: &str = "/sys/firmware/acpi/platform_profile";

    /// The bytes of one device event read at once.
    const BUFFER: usize = 1 << 13;

    /// What the device events are called where they fail.
    const EVENTS: &str = "the kernel's device events";

    /// The kernel's device events of power supplies, and the platform profile's changes where it has one.
    pub(super) struct Uevents {
        socket: OwnedFd,
        profile: Option<File>,
        buffer: Vec<u8>,
    }

    impl Uevents {
        pub(super) fn bound() -> Result<Self, String> {
            tried(
                socket_with(
                    AddressFamily::NETLINK,
                    SocketType::DGRAM,
                    SocketFlags::CLOEXEC | SocketFlags::NONBLOCK,
                    Some(netlink::KOBJECT_UEVENT),
                ),
                &format!("open {EVENTS}"),
            )
            .and_then(|socket| {
                tried(
                    bind(&socket, &netlink::SocketAddrNetlink::new(0, KERNEL_GROUP)),
                    &format!("listen to {EVENTS}"),
                )
                .map(|()| Self::over(socket, File::open(PROFILE).ok()))
            })
        }

        /// The events of a socket that reads one datagram at a time, and of the profile where there is one.
        fn over(socket: OwnedFd, mut profile: Option<File>) -> Self {
            if let Some(file) = profile.as_mut() {
                drained(file);
            }

            Self {
                socket,
                profile,
                buffer: vec![0; BUFFER],
            }
        }
    }

    impl Notices for Uevents {
        fn next(&mut self, within: Duration) -> Result<bool, String> {
            let timeout = Timespec::try_from(within)
                .map_err(|error| format!("cannot wait {within:?}: {error}"))?;
            let mut fds = vec![PollFd::new(&self.socket, PollFlags::IN)];
            fds.extend(
                self.profile
                    .as_ref()
                    .map(|file| PollFd::new(file, PollFlags::PRI | PollFlags::ERR)),
            );

            if !polled(poll(&mut fds, Some(&timeout)), EVENTS)? {
                return Ok(false);
            }

            let ready = |at: usize| fds.get(at).is_some_and(|fd| !fd.revents().is_empty());
            let (events, profiled) = (ready(0), ready(1));
            drop(fds);
            let mut changed = false;

            while events
                && let Some((read, _)) = taken(
                    recv(&self.socket, &mut self.buffer[..], RecvFlags::empty()),
                    EVENTS,
                )?
            {
                changed |= self.buffer.get(..read).is_some_and(is_power_supply);
            }

            if profiled && let Some(file) = self.profile.as_mut() {
                drained(file);
                changed = true;
            }

            Ok(changed)
        }
    }

    /// Whether a device event is one of a power supply.
    fn is_power_supply(event: &[u8]) -> bool {
        event
            .windows(POWER_SUPPLY.len())
            .any(|part| part == POWER_SUPPLY)
    }

    /// The profile read from its start, as the kernel asks before it notifies the next change.
    fn drained(file: &mut File) {
        let mut text = String::new();
        let _ = file
            .seek(SeekFrom::Start(0))
            .and_then(|_| file.read_to_string(&mut text));
    }

    #[cfg(test)]
    mod tests {
        use std::fs::File;
        use std::io::{Seek as _, SeekFrom, Write as _};
        use std::os::fd::OwnedFd;
        use std::time::Duration;

        use rustix::net::{AddressFamily, SendFlags, SocketFlags, SocketType, send, socketpair};

        use super::Uevents;
        use crate::watching::Notices;

        fn pair() -> (OwnedFd, OwnedFd) {
            socketpair(
                AddressFamily::UNIX,
                SocketType::DGRAM,
                SocketFlags::CLOEXEC | SocketFlags::NONBLOCK,
                None,
            )
            .unwrap()
        }

        #[test]
        fn tells_a_change_of_a_power_supply_and_of_the_profile() {
            let (socket, kernel) = pair();
            let mut events = Uevents::over(socket, None);

            assert_eq!(events.next(Duration::ZERO), Ok(false));

            send(
                &kernel,
                b"change@/devices/x\0ACTION=change\0SUBSYSTEM=net\0",
                SendFlags::empty(),
            )
            .unwrap();

            assert_eq!(events.next(Duration::from_secs(1)), Ok(false));

            send(&kernel, b"change@/x\0SUBSYSTEM=net\0", SendFlags::empty()).unwrap();
            send(
                &kernel,
                b"change@/x\0SUBSYSTEM=power_supply\0",
                SendFlags::empty(),
            )
            .unwrap();

            assert_eq!(events.next(Duration::from_secs(1)), Ok(true));

            let (reader, writer) = std::io::pipe().unwrap();
            drop(writer);
            let (socket, _kernel) = pair();
            let mut profiled = Uevents::over(socket, Some(File::from(OwnedFd::from(reader))));

            assert_eq!(profiled.next(Duration::from_secs(1)), Ok(true));
            assert!(
                events
                    .next(Duration::MAX)
                    .is_err_and(|why| why.starts_with("cannot wait"))
            );
        }

        #[test]
        fn reads_a_profile_it_can_seek_from_its_start_and_waits_on_it() {
            let path =
                std::env::temp_dir().join(format!("outbreak-profile-{}", std::process::id()));
            std::fs::write(&path, b"balanced\n").unwrap();
            let (socket, _kernel) = pair();
            let mut profile = File::open(&path).unwrap();
            profile.seek(SeekFrom::Start(3)).unwrap();
            let mut events = Uevents::over(socket, Some(profile));
            std::fs::remove_file(&path).unwrap();

            assert_eq!(
                events
                    .profile
                    .as_mut()
                    .map(|profile| profile.stream_position().unwrap()),
                Some(9)
            );
            assert!(events.next(Duration::ZERO).is_ok());
        }

        #[test]
        fn says_why_it_cannot_read_its_events() {
            let (reader, mut writer) = std::io::pipe().unwrap();
            writer.write_all(b"change@/x\0").unwrap();
            let mut events = Uevents::over(OwnedFd::from(reader), None);

            assert!(
                events
                    .next(Duration::from_secs(1))
                    .is_err_and(|why| why.starts_with("cannot read the kernel's device events"))
            );
        }

        #[test]
        fn binds_to_the_kernel_s_device_events() {
            let mut events = Uevents::bound().unwrap();

            assert!(
                rustix::fs::fcntl_getfl(&events.socket)
                    .unwrap()
                    .contains(rustix::fs::OFlags::NONBLOCK)
            );
            assert!(
                rustix::io::fcntl_getfd(&events.socket)
                    .unwrap()
                    .contains(rustix::io::FdFlags::CLOEXEC)
            );
            assert!(events.next(Duration::ZERO).is_ok());
            assert!(super::super::source().is_ok());
        }
    }
}
