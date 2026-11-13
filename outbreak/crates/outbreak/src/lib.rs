//! Outbreak's core (ADR-0118): the commands the gate runs it with, each reading
//! its input and writing one line of JSON that answers it.
//!
//! - `outbreak contract` says which contract it speaks, so the gate uses no
//!   binary that speaks another.
//! - `outbreak map vouch` reads a coverage map's text on its input and says
//!   whether it vouches for it: whether it holds nothing the gate's careful
//!   reading drops, so the gate may build the map straight from its text.
//! - `outbreak map split [--threads <n>]` reads a split request on its input,
//!   and writes each slice of the whole map it names, as the gate writes it,
//!   or declines where the whole map is not one the gate wrote.
//! - `outbreak report outline` reads a report request on its input, and
//!   answers the outline of the map of php-code-coverage's PHP report it
//!   names: each file, and the files each test ran a line of.
//! - `outbreak report convert` reads a report request on its input, and writes
//!   the map of the report it names as the gate writes it, or declines a
//!   report it does not read.
//!
//! - `outbreak cache pull` reads a cache request on its input, and pulls the
//!   packs of proofs it names from their store into the gate's directory
//!   (ADR-0131).
//! - `outbreak cache push` reads a cache request on its input, and merges the
//!   entries it names into their class's pack in the store.
//! - `outbreak graph reach` reads a graph request on its input, and answers
//!   which test files reach each file it names, and what they reach
//!   (ADR-0132).
//! - `outbreak serve <root> [--detach] [--idle <s>] [--snapshot-every <s>]
//!   [--sync-every <s>] [-- <child> …]` serves the project's state on a
//!   socket until it stops (ADR-0133); with `--detach` it starts the daemon
//!   in a process group of its own and answers its process id.
//! - `outbreak lsp [--stdio] [--gate <path>] [--php <binary>] [--severity <s>]
//!   [-- <watch option> …]` speaks the Language Server Protocol on its
//!   standard streams until the editor asks it to exit, showing the survivors
//!   `mutation-gate watch` tells of (ADR-0133 decision 10); it answers no line
//!   of JSON of its own.
//!
//! An answer is always one line of JSON with `"outbreak": 1`. A command that
//! cannot run, for its arguments or for a file it cannot read or write, says
//! why on its error stream and ends with a code other than nought, and the gate
//! does the work itself.

mod report;
mod split;

use std::fmt;
use std::io::{self, BufReader, Read, Write};
use std::num::NonZero;

use outbreak_map::json::Refused;
use outbreak_map::map;

/// The contract every answer is written in.
pub const CONTRACT: u32 = 1;

/// The command that serves an editor on the process's own standard streams.
pub const LSP: &str = "lsp";

/// Why a command could not run.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Failure {
    /// The arguments name no command this binary runs.
    Usage(String),
    /// A file or a stream could not be read or written.
    Io(String),
}

impl Failure {
    /// The code the process ends with: 2 for its arguments, 1 for anything else.
    #[must_use]
    pub fn code(&self) -> u8 {
        match self {
            Self::Usage(_) => 2,
            Self::Io(_) => 1,
        }
    }
}

impl fmt::Display for Failure {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Usage(why) | Self::Io(why) => formatter.write_str(why),
        }
    }
}

/// The verb that serves a project's state.
const SERVE: &str = "serve";

/// How to run it, as it says where its arguments name no command.
const USAGE: &str = "usage: outbreak contract | outbreak map vouch | outbreak map split [--threads <n>] | outbreak report outline | outbreak report convert | outbreak cache pull | outbreak cache push | outbreak graph reach | outbreak serve <root> [--detach] [--idle <s>] [--snapshot-every <s>] [--sync-every <s>] [-- <child> ...] | outbreak lsp [--stdio] [--gate <path>] [--php <binary>] [--severity <s>] [-- <watch option> ...]";

/// Runs the command the arguments name, reading its input and writing its answer.
///
/// # Errors
///
/// Fails where the arguments name no command, or a stream or file cannot be read or written.
pub fn run(
    arguments: &[String],
    input: &mut impl Read,
    output: &mut impl Write,
    cores: NonZero<usize>,
) -> Result<(), Failure> {
    let words: Vec<&str> = arguments.iter().map(String::as_str).collect();
    let answer = match words.as_slice() {
        ["contract"] => format!(
            r#"{{"outbreak":{CONTRACT},"version":"{}"}}"#,
            env!("CARGO_PKG_VERSION")
        ),
        ["map", "vouch"] => vouch(&read_all(input)?),
        ["map", "split"] => split::run(&read_all(input)?, cores)?,
        ["map", "split", "--threads", threads] => {
            split::run(&read_all(input)?, threads_of(threads)?)?
        }
        ["report", "outline"] => report::outline(&read_all(input)?)?,
        ["report", "convert"] => report::convert(&read_all(input)?)?,
        ["cache", "pull"] => cached("pulled", outbreak_cache::pull(&read_all(input)?))?,
        ["cache", "push"] => cached("pushed", outbreak_cache::push(&read_all(input)?))?,
        ["graph", "reach"] => {
            outbreak_graph::answer(&read_all(input)?, CONTRACT).map_err(Failure::Usage)?
        }
        [SERVE, ..] => served(arguments.get(1..).unwrap_or_default())?,
        _ => return Err(Failure::Usage(USAGE.to_owned())),
    };

    writeln!(output, "{answer}").map_err(failed("answer"))
}

/// `outbreak lsp` on the process's standard streams, which the threads it starts write to too.
///
/// # Errors
///
/// Fails where the arguments name no option it takes, or the editor's streams break or close
/// before it asked the server to shut down.
pub fn lsp(arguments: &[String]) -> Result<(), Failure> {
    let options = outbreak_lsp::options(arguments).map_err(Failure::Usage)?;

    outbreak_lsp::serve(&options, BufReader::new(io::stdin()), io::stdout()).map_err(Failure::Io)
}

/// What `outbreak serve` answers once it stopped, or once the daemon it detached started.
#[cfg(unix)]
fn served(arguments: &[String]) -> Result<String, Failure> {
    let (options, detach) = outbreak_serve::options(arguments).map_err(Failure::Usage)?;

    if detach {
        let binary = std::env::current_exe().map_err(failed("find itself"))?;
        let child = arguments
            .iter()
            .position(|argument| argument == "--")
            .unwrap_or(arguments.len());
        let own: Vec<String> = std::iter::once(SERVE.to_owned())
            .chain(
                arguments
                    .iter()
                    .enumerate()
                    .filter(|(at, argument)| *at >= child || *argument != "--detach")
                    .map(|(_, argument)| argument.clone()),
            )
            .collect();
        let started = outbreak_serve::server::detached(&binary, &own).map_err(Failure::Io)?;

        return Ok(format!(r#"{{"outbreak":{CONTRACT},"started":{started}}}"#));
    }

    outbreak_serve::server::serve(&options, CONTRACT).map_err(Failure::Io)?;

    Ok(format!(r#"{{"outbreak":{CONTRACT},"served":true}}"#))
}

/// `outbreak serve` serves on Unix alone.
#[cfg(not(unix))]
fn served(_: &[String]) -> Result<String, Failure> {
    Err(Failure::Usage("serve runs on Unix alone".to_owned()))
}

/// How many packs a command of the cache moved, as its answer says it; or why it moved none.
fn cached(done: &str, moved: Result<usize, outbreak_cache::Failure>) -> Result<String, Failure> {
    moved
        .map(|count| format!(r#"{{"outbreak":{CONTRACT},"{done}":{count}}}"#))
        .map_err(|failure| Failure::Io(failure.to_string()))
}

/// Whether a map's text is vouched for, and why not where it is not.
fn vouch(text: &[u8]) -> String {
    match map::read(text) {
        Ok(_) => format!(r#"{{"outbreak":{CONTRACT},"vouched":true}}"#),
        Err(refused) => format!(
            r#"{{"outbreak":{CONTRACT},"vouched":false,"why":"{}"}}"#,
            said(&refused)
        ),
    }
}

/// A refusal as an answer says it.
fn said(refused: &Refused) -> String {
    format!("expected {} at byte {}", refused.expected, refused.at)
}

fn threads_of(threads: &str) -> Result<NonZero<usize>, Failure> {
    threads.parse().map_err(|_| {
        Failure::Usage(format!(
            "--threads takes a whole number of one or more, not {threads}"
        ))
    })
}

/// Why a step on the process's streams or its own binary failed, from the error it failed with.
fn failed(doing: &str) -> impl FnOnce(io::Error) -> Failure + '_ {
    move |error| Failure::Io(format!("cannot {doing}: {error}"))
}

fn read_all(input: &mut impl Read) -> Result<Vec<u8>, Failure> {
    let mut bytes = Vec::new();
    input
        .read_to_end(&mut bytes)
        .map_err(failed("read the input"))?;

    Ok(bytes)
}

#[cfg(test)]
mod tests {
    use std::io::{self, Read, Write};
    use std::num::NonZero;

    use super::{CONTRACT, Failure, run};

    fn ran(arguments: &[&str], input: &str) -> Result<String, Failure> {
        let arguments: Vec<String> = arguments.iter().map(|word| (*word).to_owned()).collect();
        let mut output = Vec::new();
        run(
            &arguments,
            &mut input.as_bytes(),
            &mut output,
            NonZero::<usize>::MIN,
        )?;

        Ok(String::from_utf8(output).unwrap())
    }

    struct Broken;

    impl Read for Broken {
        fn read(&mut self, _: &mut [u8]) -> io::Result<usize> {
            Err(io::Error::other("gone"))
        }
    }

    impl Write for Broken {
        fn write(&mut self, _: &[u8]) -> io::Result<usize> {
            Err(io::Error::other("gone"))
        }

        fn flush(&mut self) -> io::Result<()> {
            Ok(())
        }
    }

    #[test]
    fn says_which_contract_it_speaks() {
        assert_eq!(
            ran(&["contract"], "").unwrap(),
            format!(
                "{{\"outbreak\":{CONTRACT},\"version\":\"{}\"}}\n",
                env!("CARGO_PKG_VERSION")
            )
        );
    }

    #[test]
    fn vouches_for_a_map_the_careful_reading_reads_whole() {
        assert_eq!(
            ran(&["map", "vouch"], r#"{"format":1,"tests":[],"files":{}}"#).unwrap(),
            "{\"outbreak\":1,\"vouched\":true}\n"
        );
    }

    #[test]
    fn says_why_it_vouches_for_no_other_text() {
        assert_eq!(
            ran(&["map", "vouch"], "[]").unwrap(),
            "{\"outbreak\":1,\"vouched\":false,\"why\":\"expected an object at byte 0\"}\n"
        );
    }

    #[test]
    fn runs_each_report_command_on_its_input() {
        assert!(matches!(
            ran(&["report", "outline"], "[]").unwrap_err(),
            Failure::Usage(why) if why.starts_with("the report request is not one")
        ));
        assert!(matches!(
            ran(&["report", "convert"], "[]").unwrap_err(),
            Failure::Usage(why) if why.starts_with("the report request is not one")
        ));
    }

    #[test]
    fn answers_the_graph_on_its_input() {
        assert_eq!(
            ran(
                &["graph", "reach"],
                r#"{"root":"/nowhere","sources":[],"tests":[],"reach":[]}"#
            )
            .unwrap(),
            "{\"outbreak\":1,\"reaching\":{},\"joined\":{},\"named\":{},\"wrong\":[],\"unplaced\":{}}\n"
        );
        assert!(matches!(
            ran(&["graph", "reach"], "[]").unwrap_err(),
            Failure::Usage(why) if why.starts_with("the graph's request")
        ));
    }

    #[test]
    fn says_how_many_packs_the_cache_moved_or_why_it_moved_none() {
        assert_eq!(
            ran(
                &["cache", "push"],
                r#"{"store":"actions","base":"b","limits":{"packed":1,"unpacked":1},"packs":{}}"#
            )
            .unwrap(),
            "{\"outbreak\":1,\"pushed\":0}\n"
        );
        assert!(matches!(
            ran(&["cache", "pull"], "{}").unwrap_err(),
            Failure::Io(why) if why.starts_with("the cache's request")
        ));
    }

    #[test]
    fn says_why_it_cannot_serve() {
        assert_eq!(
            ran(&["serve"], "").unwrap_err(),
            Failure::Usage("serve takes the project's root".to_owned())
        );
        assert!(matches!(
            ran(&["serve", "/nonexistent/root"], "").unwrap_err(),
            Failure::Io(why) if why.starts_with("cannot make")
        ));
    }

    #[test]
    fn refuses_arguments_that_name_no_command() {
        for arguments in [&[][..], &["map"], &["map", "vouch", "x"], &["other"]] {
            let failure = ran(arguments, "").unwrap_err();

            assert_eq!(failure.code(), 2);
            assert!(failure.to_string().starts_with("usage: outbreak"));
        }
    }

    #[test]
    fn splits_on_as_many_threads_as_it_is_given_cores() {
        assert!(matches!(
            ran(&["map", "split"], "[]").unwrap_err(),
            Failure::Usage(why) if why.starts_with("the split request is not one")
        ));
    }

    #[test]
    fn refuses_threads_that_are_not_one_or_more() {
        for threads in ["0", "x", "-1"] {
            let failure = ran(&["map", "split", "--threads", threads], "{}").unwrap_err();

            assert_eq!(
                failure,
                Failure::Usage(format!(
                    "--threads takes a whole number of one or more, not {threads}"
                ))
            );
        }
    }

    #[test]
    fn fails_where_its_input_or_output_breaks() {
        let arguments = vec!["contract".to_owned()];
        let failure = run(
            &["map".to_owned(), "vouch".to_owned()],
            &mut Broken,
            &mut Vec::new(),
            NonZero::<usize>::MIN,
        )
        .unwrap_err();

        assert_eq!(failure, Failure::Io("cannot read the input: gone".into()));
        assert_eq!(failure.code(), 1);
        assert_eq!(
            run(
                &arguments,
                &mut io::empty(),
                &mut Broken,
                NonZero::<usize>::MIN
            )
            .unwrap_err(),
            Failure::Io("cannot answer: gone".into())
        );
        Broken.flush().unwrap();
    }
}
