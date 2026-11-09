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
//!
//! An answer is always one line of JSON with `"outbreak": 1`. A command that
//! cannot run, for its arguments or for a file it cannot read or write, says
//! why on its error stream and ends with a code other than nought, and the gate
//! does the work itself.

mod report;
mod split;

use std::fmt;
use std::io::{Read, Write};
use std::num::NonZero;

use outbreak_map::json::Refused;
use outbreak_map::map;

/// The contract every answer is written in.
pub const CONTRACT: u32 = 1;

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

/// How to run it, as it says where its arguments name no command.
const USAGE: &str = "usage: outbreak contract | outbreak map vouch | outbreak map split [--threads <n>] | outbreak report outline | outbreak report convert | outbreak cache pull | outbreak cache push";

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
        _ => return Err(Failure::Usage(USAGE.to_owned())),
    };

    writeln!(output, "{answer}").map_err(|error| Failure::Io(format!("cannot answer: {error}")))
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

fn read_all(input: &mut impl Read) -> Result<Vec<u8>, Failure> {
    let mut bytes = Vec::new();
    input
        .read_to_end(&mut bytes)
        .map_err(|error| Failure::Io(format!("cannot read the input: {error}")))?;

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
