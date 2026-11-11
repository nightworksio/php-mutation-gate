//! `outbreak lsp` (ADR-0133 decision 10): a language server on standard input and output. Once
//! the editor initialized it, it starts `mutation-gate watch --output=events` in the editor's
//! workspace and shows each survivor that tells of as a diagnostic on its site's line, with the
//! gate's `explain` and `stub` as its code actions. The daemon `watch` talks to watches the
//! files, so the server asks the editor for no file events.

pub mod frame;
pub mod gate;
pub mod message;
pub mod survivors;
pub mod uri;

use std::fmt::Write as _;
use std::io::{BufRead, BufReader, Read, Write};
use std::path::PathBuf;
use std::process::{Child, Output};
use std::sync::{Arc, Mutex, MutexGuard, PoisonError};
use std::thread;

use outbreak_graph::quoted;

use crate::gate::{Action, Gate};
use crate::message::Fault;
use crate::survivors::{Event, SOURCE, Severity, Survivors, is_mutant};

/// The gate's command line, relative to the workspace's root, where the options name none.
const GATE: &str = "vendor/bin/mutation-gate";

/// The PHP the gate runs through, where the options name none: none where a script runs itself.
#[cfg(windows)]
const PHP: Option<&str> = Some("php");
#[cfg(not(windows))]
const PHP: Option<&str> = None;

/// The method that shows a unit's diagnostics.
const PUBLISH: &str = "textDocument/publishDiagnostics";

/// The kind of code action each of a survivor's is.
const QUICKFIX: &str = "quickfix";

/// The method that shows the user a message.
const SHOW: &str = "window/showMessage";

/// What the server says where the editor opened no workspace.
const NO_ROOT: &str =
    "No workspace folder with a file URI is open, so mutation-gate has nothing to watch.";

/// What the server says where `watch` did not start.
const UNSTARTED: &str = "mutation-gate watch did not start: ";

/// What the server says where `watch` ended without the server asking it to.
const ENDED: &str =
    "mutation-gate watch ended ({status}); restart the language server to watch again.";

/// What the server says where an action's command did not start.
const UNRUN: &str = "mutation-gate did not start: ";

/// How a server runs.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Options {
    /// The gate's command line, relative to the workspace's root where it is relative.
    pub gate: PathBuf,
    /// The PHP the gate runs through, where it does not run itself.
    pub php: Option<PathBuf>,
    /// How the editor shows a survivor.
    pub severity: Severity,
    /// The options added to `watch`.
    pub watching: Vec<String>,
}

/// The options of `outbreak lsp [--stdio] [--gate <path>] [--php <binary>] [--severity <s>]
/// [-- <watch option> …]`.
///
/// # Errors
///
/// Refuses an argument it does not take, or an option with no value or one it does not take.
pub fn options(arguments: &[String]) -> Result<Options, String> {
    let mut options = Options {
        gate: PathBuf::from(GATE),
        php: PHP.map(PathBuf::from),
        severity: Severity::Warning,
        watching: Vec::new(),
    };
    let mut rest = arguments.iter();

    while let Some(argument) = rest.next() {
        match argument.as_str() {
            "--stdio" => {}
            "--gate" => options.gate = PathBuf::from(value(rest.next(), argument)?),
            "--php" => options.php = Some(PathBuf::from(value(rest.next(), argument)?)),
            "--severity" => {
                options.severity =
                    Severity::named(value(rest.next(), argument)?).ok_or_else(|| {
                        String::from("--severity takes error, warning, information or hint")
                    })?;
            }
            "--" => {
                options.watching = rest.cloned().collect();
                break;
            }
            other => return Err(format!("lsp takes no {other}")),
        }
    }

    Ok(options)
}

/// The value given after an option.
fn value<'a>(given: Option<&'a String>, option: &str) -> Result<&'a str, String> {
    given
        .map(String::as_str)
        .ok_or_else(|| format!("{option} takes a value"))
}

/// Serves the editor on these streams until it asks the server to exit or leaves.
///
/// # Errors
///
/// Fails where the editor's stream breaks, the server cannot write to it, or the editor leaves
/// without asking the server to shut down first.
pub fn serve<W: Write + Send + 'static>(
    options: &Options,
    mut input: impl BufRead,
    output: W,
) -> Result<(), String> {
    let mut session = Session::new(options.clone(), output);
    let ended = loop {
        match frame::read(&mut input) {
            Ok(Some(content)) => match session.handled(&content) {
                Ok(Flow::Next) => {}
                Ok(Flow::Exit) => break Ok(()),
                Err(failed) => break Err(failed),
            },
            Ok(None) => break Ok(()),
            Err(error) => break Err(format!("cannot read the editor: {error}")),
        }
    };

    session.stop();
    ended?;

    if session.phase == Phase::ShuttingDown {
        Ok(())
    } else {
        Err(String::from(
            "the editor left without asking the server to shut down",
        ))
    }
}

/// Where the session stands in the protocol's lifecycle.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Phase {
    Uninitialized,
    Running,
    ShuttingDown,
}

/// Whether the session reads on.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Flow {
    Next,
    Exit,
}

/// How a message the server shows the user is meant, by the type the protocol numbers it with.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(u8)]
enum Shown {
    Error = 1,
    Warning = 2,
    Info = 3,
}

/// The editor's end of the server's output, shared by every thread that writes to it.
struct Client<W> {
    output: Arc<Mutex<W>>,
}

impl<W> Clone for Client<W> {
    fn clone(&self) -> Self {
        Self {
            output: Arc::clone(&self.output),
        }
    }
}

impl<W: Write> Client<W> {
    fn send(&self, content: &str) -> Result<(), String> {
        frame::write(&mut *locked(&self.output), content)
            .map_err(|error| format!("cannot write to the editor: {error}"))
    }

    fn show(&self, shown: Shown, text: &str) {
        let params = format!(r#"{{"type":{},"message":{}}}"#, shown as u8, quoted(text));
        let _ = self.send(&message::notification(SHOW, &params));
    }
}

struct Session<W> {
    options: Options,
    client: Client<W>,
    phase: Phase,
    project: Option<(String, Gate)>,
    watching: Arc<Mutex<Option<Child>>>,
}

impl<W: Write + Send + 'static> Session<W> {
    fn new(options: Options, output: W) -> Self {
        Self {
            options,
            client: Client {
                output: Arc::new(Mutex::new(output)),
            },
            phase: Phase::Uninitialized,
            project: None,
            watching: Arc::new(Mutex::new(None)),
        }
    }

    fn handled(&mut self, content: &[u8]) -> Result<Flow, String> {
        let Some(read) = message::read(content) else {
            self.client
                .send(&message::error("null", Fault::Unreadable))?;
            return Ok(Flow::Next);
        };

        match (read.id, read.method.as_deref()) {
            (_, Some("exit")) => return Ok(Flow::Exit),
            (Some(id), Some("initialize")) if self.phase == Phase::Uninitialized => {
                self.initialize(id, read.params)?;
            }
            (None, Some("initialized")) if self.phase == Phase::Running => self.watch(),
            (Some(id), Some(_)) if self.phase == Phase::Uninitialized => {
                self.client
                    .send(&message::error(id, Fault::Uninitialized))?;
            }
            (Some(id), Some("shutdown")) if self.phase == Phase::Running => {
                self.phase = Phase::ShuttingDown;
                self.stop();
                self.client.send(&message::result(id, "null"))?;
            }
            (Some(id), Some(_)) if self.phase == Phase::ShuttingDown => {
                self.client.send(&message::error(id, Fault::Invalid))?;
            }
            (Some(id), Some("textDocument/codeAction")) => {
                self.client
                    .send(&message::result(id, &actions(read.params)))?;
            }
            (Some(id), Some("workspace/executeCommand")) => self.execute(id, read.params)?,
            (Some(id), Some("initialize")) => {
                self.client.send(&message::error(id, Fault::Invalid))?;
            }
            (Some(id), Some(_)) => self.client.send(&message::error(id, Fault::Unknown))?,
            _ => {}
        }

        Ok(Flow::Next)
    }

    fn initialize(&mut self, id: &str, params: Option<&[u8]>) -> Result<(), String> {
        self.phase = Phase::Running;
        self.project = params.and_then(root).and_then(|root| {
            let path = uri::path(&root)?;
            let gate = Gate::of(
                &path,
                &self.options.gate,
                self.options.php.as_deref(),
                &self.options.watching,
            );

            Some((root, gate))
        });

        self.client.send(&message::result(id, &capabilities()))
    }

    fn watch(&self) {
        let Some((root, gate)) = &self.project else {
            self.client.show(Shown::Error, NO_ROOT);
            return;
        };

        match gate.watch() {
            Ok(mut child) => {
                let lines = child.stdout.take();
                *locked(&self.watching) = Some(child);

                if let Some(lines) = lines {
                    let (client, watching, root, severity) = (
                        self.client.clone(),
                        Arc::clone(&self.watching),
                        root.clone(),
                        self.options.severity,
                    );
                    thread::spawn(move || told(lines, &client, &watching, &root, severity));
                }
            }
            Err(error) => self
                .client
                .show(Shown::Error, &format!("{UNSTARTED}{error}")),
        }
    }

    fn execute(&self, id: &str, params: Option<&[u8]>) -> Result<(), String> {
        let action = params
            .and_then(|params| message::member(params, "command"))
            .and_then(message::string)
            .and_then(|command| Action::named(&command));
        let mutant = params
            .and_then(|params| message::member(params, "arguments"))
            .and_then(message::items)
            .filter(|arguments| arguments.len() == 1)
            .and_then(|arguments| arguments.first().copied().and_then(message::string))
            .filter(|mutant| is_mutant(mutant));

        let (Some(action), Some(mutant), Some((_, gate))) = (action, mutant, &self.project) else {
            return self.client.send(&message::error(id, Fault::Params));
        };

        let (client, gate, id) = (self.client.clone(), gate.clone(), id.to_owned());
        thread::spawn(move || {
            match gate.act(action, &mutant) {
                Ok(output) => client.show(
                    if output.status.success() {
                        Shown::Info
                    } else {
                        Shown::Error
                    },
                    &said(&output),
                ),
                Err(error) => client.show(Shown::Error, &format!("{UNRUN}{error}")),
            }

            let _ = client.send(&message::result(&id, "null"));
        });

        Ok(())
    }

    fn stop(&self) {
        let child = locked(&self.watching).take();

        if let Some(child) = child {
            let _ = gate::stop(child);
        }
    }
}

/// Reads `watch`'s lines and shows the diagnostics each event moved; once they end, ends
/// `watch` where the server did not stop it already, and says so where the editor still reads.
fn told<W: Write>(
    lines: impl Read,
    client: &Client<W>,
    watching: &Mutex<Option<Child>>,
    root: &str,
    severity: Severity,
) {
    let mut survivors = Survivors::default();
    let mut heard = true;

    for line in BufReader::new(lines).split(b'\n') {
        let Ok(line) = line else {
            break;
        };
        let Some(unit) = Event::read(&line).and_then(|event| survivors.take(event)) else {
            continue;
        };
        let params = format!(
            r#"{{"uri":{},"diagnostics":{}}}"#,
            quoted(&uri::of(root, &unit)),
            survivors.diagnostics(&unit, severity)
        );

        if client
            .send(&message::notification(PUBLISH, &params))
            .is_err()
        {
            heard = false;
            break;
        }
    }

    let unstopped = locked(watching).take();

    match unstopped {
        Some(child) if heard => {
            let status = gate::ended(child)
                .map_or_else(|error| error.to_string(), |status| status.to_string());
            client.show(Shown::Warning, &ENDED.replace("{status}", &status));
        }
        Some(child) => {
            let _ = gate::stop(child);
        }
        None => {}
    }
}

/// The workspace's root URI the initialize request names: its `rootUri`, else its first
/// workspace folder's.
fn root(params: &[u8]) -> Option<String> {
    message::member(params, "rootUri")
        .and_then(message::string)
        .or_else(|| {
            message::member(params, "workspaceFolders")
                .and_then(message::items)
                .and_then(|folders| folders.first().copied())
                .and_then(|folder| message::member(folder, "uri"))
                .and_then(message::string)
        })
}

/// What the server answers the initialize request with.
fn capabilities() -> String {
    let commands: Vec<String> = Action::ALL
        .iter()
        .map(|action| quoted(action.command()))
        .collect();

    format!(
        r#"{{"capabilities":{{"codeActionProvider":{{"codeActionKinds":[{}]}},"executeCommandProvider":{{"commands":[{}]}}}},"serverInfo":{{"name":"outbreak lsp","version":{}}}}}"#,
        quoted(QUICKFIX),
        commands.join(","),
        quoted(env!("CARGO_PKG_VERSION"))
    )
}

/// The code actions of each survivor's diagnostic a code action request names.
fn actions(params: Option<&[u8]>) -> String {
    let diagnostics = params
        .and_then(|params| message::at(params, &["context", "diagnostics"]))
        .and_then(message::items)
        .unwrap_or_default();
    let mut written = String::from("[");

    for diagnostic in diagnostics {
        let ours = message::member(diagnostic, "source")
            .and_then(message::string)
            .as_deref()
            == Some(SOURCE);
        let mutant = message::member(diagnostic, "code")
            .and_then(message::string)
            .filter(|code| is_mutant(code));
        let (true, Some(mutant)) = (ours, mutant) else {
            continue;
        };

        for action in Action::ALL {
            let _ = write!(
                written,
                r#"{}{{"title":{},"kind":{},"diagnostics":[{}],"command":{{"title":{},"command":{},"arguments":[{}]}}}}"#,
                if written.len() == 1 { "" } else { "," },
                quoted(action.title()),
                quoted(QUICKFIX),
                String::from_utf8_lossy(diagnostic),
                quoted(action.title()),
                quoted(action.command()),
                quoted(&mutant)
            );
        }
    }

    written.push(']');
    written
}

/// What a command said on its output and its error output, as one text.
fn said(output: &Output) -> String {
    let mut text = String::from_utf8_lossy(&output.stdout).into_owned();
    text.push_str(&String::from_utf8_lossy(&output.stderr));

    text.trim().to_owned()
}

fn locked<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(PoisonError::into_inner)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Clone, Default)]
    struct Written(Arc<Mutex<Vec<u8>>>);

    impl Write for Written {
        fn write(&mut self, bytes: &[u8]) -> std::io::Result<usize> {
            locked(&self.0).write(bytes)
        }

        fn flush(&mut self) -> std::io::Result<()> {
            Ok(())
        }
    }

    fn framed(messages: &[&str]) -> Vec<u8> {
        let mut bytes = Vec::new();

        for content in messages {
            frame::write(&mut bytes, content).unwrap();
        }

        bytes
    }

    fn answers(written: &Written) -> Vec<String> {
        let bytes = locked(&written.0).clone();
        let mut rest = bytes.as_slice();
        let mut read = Vec::new();

        while let Some(content) = frame::read(&mut rest).unwrap() {
            read.push(String::from_utf8(content).unwrap());
        }

        read
    }

    fn served(messages: &[&str]) -> (Result<(), String>, Vec<String>) {
        let written = Written::default();
        let ended = serve(
            &options(&[]).unwrap(),
            framed(messages).as_slice(),
            written.clone(),
        );

        (ended, answers(&written))
    }

    #[test]
    fn reads_its_options() {
        let read = options(
            &[
                "--stdio",
                "--gate",
                "bin/gate",
                "--php",
                "php8.4",
                "--severity",
                "error",
                "--",
                "--config=x",
            ]
            .map(String::from),
        )
        .unwrap();

        assert_eq!(read.gate, PathBuf::from("bin/gate"));
        assert_eq!(read.php, Some(PathBuf::from("php8.4")));
        assert_eq!(read.severity, Severity::Error);
        assert_eq!(read.watching, ["--config=x"]);
        assert_eq!(options(&[]).unwrap().gate, PathBuf::from(GATE));
        assert!(options(&["--severity".to_owned(), "loud".to_owned()]).is_err());
        assert!(options(&["--gate".to_owned()]).is_err());
        assert!(options(&["--port".to_owned()]).is_err());
    }

    #[test]
    fn answers_through_its_lifecycle() {
        let (ended, answers) = served(&[
            r#"{"jsonrpc":"2.0","id":1,"method":"textDocument/codeAction","params":{}}"#,
            r#"{"jsonrpc":"2.0","id":2,"method":"initialize","params":{"rootUri":null,"workspaceFolders":null}}"#,
            r#"{"jsonrpc":"2.0","method":"initialized","params":{}}"#,
            r#"{"jsonrpc":"2.0","id":3,"method":"textDocument/hover","params":{}}"#,
            r#"{"jsonrpc":"2.0","id":4,"method":"workspace/executeCommand","params":{"command":"mutation-gate.explain","arguments":["0123456789ab"]}}"#,
            "[]",
            r#"{"jsonrpc":"2.0","id":5,"method":"shutdown"}"#,
            r#"{"jsonrpc":"2.0","id":6,"method":"textDocument/codeAction","params":{}}"#,
            r#"{"jsonrpc":"2.0","method":"exit"}"#,
        ]);

        assert_eq!(ended, Ok(()));
        assert!(answers[0].contains(r#""id":1,"error":{"code":-32002"#));
        assert!(answers[1].contains(r#""id":2,"result":{"capabilities""#));
        assert!(answers[2].contains(SHOW) && answers[2].contains("nothing to watch"));
        assert!(answers[3].contains(r#""id":3,"error":{"code":-32601"#));
        assert!(answers[4].contains(r#""id":4,"error":{"code":-32602"#));
        assert!(answers[5].contains(r#""id":null,"error":{"code":-32700"#));
        assert_eq!(answers[6], r#"{"jsonrpc":"2.0","id":5,"result":null}"#);
        assert!(answers[7].contains(r#""id":6,"error":{"code":-32600"#));
    }

    #[test]
    fn fails_where_the_editor_leaves_without_a_shutdown() {
        assert!(served(&[r#"{"jsonrpc":"2.0","method":"exit"}"#]).0.is_err());
        assert!(served(&[]).0.is_err());
    }

    #[test]
    fn offers_each_action_on_a_survivor_of_its_own() {
        let ours = r#"{"range":{},"code":"0123456789ab","source":"mutation-gate","message":"m"}"#;
        let params = format!(
            r#"{{"context":{{"diagnostics":[{ours},{{"code":"0123456789ab","source":"php"}},{{"code":"x","source":"mutation-gate"}}]}}}}"#
        );
        let offered = actions(Some(params.as_bytes()));

        assert_eq!(
            offered.matches(r#""kind":"quickfix""#).count(),
            Action::ALL.len()
        );
        assert!(offered.contains(&format!(r#""diagnostics":[{ours}]"#)));
        assert!(
            offered.contains(r#""command":"mutation-gate.stubWrite","arguments":["0123456789ab"]"#)
        );
        assert_eq!(actions(None), "[]");
    }

    #[test]
    fn reads_the_root_from_the_root_uri_or_the_first_folder() {
        assert_eq!(
            root(br#"{"rootUri":"file:///a"}"#).as_deref(),
            Some("file:///a")
        );
        assert_eq!(
            root(br#"{"rootUri":null,"workspaceFolders":[{"uri":"file:///b","name":"b"}]}"#)
                .as_deref(),
            Some("file:///b")
        );
        assert_eq!(root(br#"{"rootUri":null}"#), None);
    }
}
