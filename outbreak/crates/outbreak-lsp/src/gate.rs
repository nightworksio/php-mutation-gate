//! The gate's commands the server runs in the project: `watch --output=events`, whose lines it
//! reads, and the commands a survivor's code actions run.

use std::io;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitStatus, Output, Stdio};
use std::thread;
use std::time::{Duration, Instant};

/// The arguments that make `watch` say each judged mutant as one line of JSON.
const WATCH: [&str; 2] = ["watch", "--output=events"];

/// How long `watch` has to end once asked or once its output closed, before it is killed.
const STOP_WAIT: Duration = Duration::from_secs(2);

/// How often a stopping `watch` is looked at.
const STOP_LOOK: Duration = Duration::from_millis(50);

/// What a survivor's code action runs.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Action {
    /// `explain <id>`: the mutant's diff, why it stands, and what its tests miss.
    Explain,
    /// `stub <id>`: a failing test for it, printed.
    Stub,
    /// `stub <id> --write`: a failing test for it, written beside its nearest test.
    StubWrite,
}

impl Action {
    /// Every action, in the order the editor lists them.
    pub const ALL: [Self; 3] = [Self::Explain, Self::Stub, Self::StubWrite];

    /// The command the editor runs it by.
    #[must_use]
    pub const fn command(self) -> &'static str {
        match self {
            Self::Explain => "mutation-gate.explain",
            Self::Stub => "mutation-gate.stub",
            Self::StubWrite => "mutation-gate.stubWrite",
        }
    }

    /// What the editor lists it as.
    #[must_use]
    pub const fn title(self) -> &'static str {
        match self {
            Self::Explain => "Explain this mutant and show its diff",
            Self::Stub => "Show a failing test for this mutant",
            Self::StubWrite => "Write a failing test for this mutant",
        }
    }

    /// The action an editor's command names.
    #[must_use]
    pub fn named(command: &str) -> Option<Self> {
        Self::ALL
            .into_iter()
            .find(|action| action.command() == command)
    }

    fn words(self, mutant: &str) -> Vec<&str> {
        match self {
            Self::Explain => vec!["explain", mutant],
            Self::Stub => vec!["stub", mutant],
            Self::StubWrite => vec!["stub", mutant, "--write"],
        }
    }
}

/// How the gate is run in one project.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Gate {
    root: PathBuf,
    program: PathBuf,
    script: Option<PathBuf>,
    watching: Vec<String>,
}

impl Gate {
    /// The gate at this path, relative to the root where it is relative, run through this PHP
    /// where one is named, with these options added to `watch`.
    #[must_use]
    pub fn of(root: &Path, gate: &Path, php: Option<&Path>, watching: &[String]) -> Self {
        let gate = root.join(gate);
        let (program, script) = match php {
            Some(php) => (php.to_path_buf(), Some(gate)),
            None => (gate, None),
        };

        Self {
            root: root.to_path_buf(),
            program,
            script,
            watching: watching.to_vec(),
        }
    }

    /// `watch --output=events` started, its lines on a pipe and its errors on the server's.
    ///
    /// # Errors
    ///
    /// Fails where it cannot start.
    pub fn watch(&self) -> io::Result<Child> {
        self.command(&WATCH)
            .args(&self.watching)
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn()
    }

    /// What an action said once it ran for a mutant.
    ///
    /// # Errors
    ///
    /// Fails where it cannot start.
    pub fn act(&self, action: Action, mutant: &str) -> io::Result<Output> {
        self.command(&action.words(mutant))
            .stdin(Stdio::null())
            .output()
    }

    fn command(&self, words: &[&str]) -> Command {
        let mut command = Command::new(&self.program);
        command
            .args(&self.script)
            .args(words)
            .current_dir(&self.root);

        command
    }
}

/// How a started `watch` ended once asked to, killed where it has not within [`STOP_WAIT`].
///
/// # Errors
///
/// Fails where it cannot be waited for or killed.
pub fn stop(child: Child) -> io::Result<ExitStatus> {
    asked_to_end(&child);

    waited(child)
}

/// How a `watch` whose output closed ended, killed where it has not within [`STOP_WAIT`].
///
/// # Errors
///
/// Fails where it cannot be waited for or killed.
pub fn ended(child: Child) -> io::Result<ExitStatus> {
    waited(child)
}

fn waited(mut child: Child) -> io::Result<ExitStatus> {
    let since = Instant::now();

    loop {
        if let Some(status) = child.try_wait()? {
            return Ok(status);
        }

        if since.elapsed() >= STOP_WAIT {
            child.kill()?;
            return child.wait();
        }

        thread::sleep(STOP_LOOK);
    }
}

#[cfg(unix)]
fn asked_to_end(child: &Child) {
    use rustix::process::{Pid, Signal, kill_process};

    let _ = kill_process(Pid::from_child(child), Signal::TERM);
}

#[cfg(not(unix))]
fn asked_to_end(_: &Child) {}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_each_action_by_its_command() {
        for action in Action::ALL {
            assert_eq!(Action::named(action.command()), Some(action));
        }

        assert_eq!(Action::named("mutation-gate.run"), None);
        assert_eq!(
            Action::StubWrite.words("0123456789ab"),
            ["stub", "0123456789ab", "--write"]
        );
    }

    #[test]
    fn runs_the_gate_from_the_root_through_the_php_named() {
        let root = Path::new("/project");
        let through = Gate::of(
            root,
            Path::new("vendor/bin/mutation-gate"),
            Some(Path::new("php")),
            &["--config=x".to_owned()],
        );
        let command = through.command(&WATCH);

        assert_eq!(command.get_program(), "php");
        assert_eq!(
            command.get_args().collect::<Vec<_>>(),
            [
                root.join("vendor/bin/mutation-gate").as_os_str(),
                "watch".as_ref(),
                "--output=events".as_ref()
            ]
        );
        assert_eq!(command.get_current_dir(), Some(root));
        assert_eq!(
            Gate::of(root, Path::new("/bin/gate"), None, &[])
                .command(&[])
                .get_program(),
            "/bin/gate"
        );
    }

    /// A gate in a directory of its own, a shell script of this body run through `sh`.
    fn scripted(name: &str, body: &str) -> (PathBuf, Gate) {
        let root = std::env::temp_dir().join(format!("outbreak-lsp-{name}-{}", std::process::id()));
        std::fs::create_dir_all(&root).unwrap();
        std::fs::write(root.join("gate.sh"), body).unwrap();
        let gate = Gate::of(
            &root,
            Path::new("gate.sh"),
            Some(Path::new("/bin/sh")),
            &["--config=x".to_owned()],
        );

        (root, gate)
    }

    #[test]
    fn runs_each_action_for_a_mutant() {
        let (_, gate) = scripted("act", "echo \"$@\"\n");

        for (action, said) in [
            (Action::Explain, "explain 0123456789ab\n"),
            (Action::Stub, "stub 0123456789ab\n"),
            (Action::StubWrite, "stub 0123456789ab --write\n"),
        ] {
            let output = gate.act(action, "0123456789ab").unwrap();

            assert_eq!(String::from_utf8_lossy(&output.stdout), said);
        }
    }

    #[test]
    fn watches_with_the_options_given_and_waits_for_its_end() {
        let (_, gate) = scripted("watch", "echo \"$@\"\n");
        let mut child = gate.watch().unwrap();
        let mut said = String::new();
        std::io::Read::read_to_string(&mut child.stdout.take().unwrap(), &mut said).unwrap();

        assert_eq!(said, "watch --output=events --config=x\n");
        assert!(ended(child).unwrap().success());
    }

    #[test]
    fn stops_a_watch_that_ends_when_asked_and_kills_one_that_does_not() {
        let (_, asked) = scripted("asked", "exec sleep 30\n");
        let (_, stubborn) = scripted(
            "stubborn",
            "trap '' TERM\necho trapped\nwhile :; do sleep 1; done\n",
        );

        assert!(!stop(asked.watch().unwrap()).unwrap().success());

        let mut child = stubborn.watch().unwrap();
        let mut trapped = String::new();
        std::io::BufRead::read_line(
            &mut std::io::BufReader::new(child.stdout.as_mut().unwrap()),
            &mut trapped,
        )
        .unwrap();
        let since = Instant::now();

        assert_eq!(trapped, "trapped\n");
        assert!(!stop(child).unwrap().success());
        assert!(since.elapsed() >= STOP_WAIT);
    }
}
