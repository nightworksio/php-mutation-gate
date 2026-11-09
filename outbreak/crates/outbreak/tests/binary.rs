//! The binary as the gate runs it: arguments, input and output streams, and the code it ends with.

use std::error::Error;
use std::io::{self, Write};
use std::process::{Command, Stdio};

/// What a run said: the code it ended with, its output and its error stream.
type Said = (Option<i32>, String, String);

fn ran(arguments: &[&str], input: &[u8]) -> Result<Said, Box<dyn Error>> {
    let mut child = Command::new(env!("CARGO_BIN_EXE_outbreak"))
        .args(arguments)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()?;
    child
        .stdin
        .take()
        .ok_or_else(|| io::Error::other("no input"))?
        .write_all(input)?;
    let output = child.wait_with_output()?;

    Ok((
        output.status.code(),
        String::from_utf8(output.stdout)?,
        String::from_utf8(output.stderr)?,
    ))
}

#[test]
fn answers_on_its_output_and_ends_with_nought() -> Result<(), Box<dyn Error>> {
    let (code, said, errors) = ran(
        &["map", "vouch"],
        br#"{"format":1,"tests":[{"id":"a","seconds":0.1}],"files":{"src/A.php":{"1":[0]}}}"#,
    )?;

    assert_eq!(code, Some(0));
    assert_eq!(said, "{\"outbreak\":1,\"vouched\":true}\n");
    assert_eq!(errors, "");

    Ok(())
}

#[test]
fn says_why_it_cannot_run_on_its_error_stream_and_ends_with_its_code() -> Result<(), Box<dyn Error>>
{
    let (code, said, errors) = ran(&["nothing"], b"")?;

    assert_eq!(code, Some(2));
    assert_eq!(said, "");
    assert!(errors.starts_with("outbreak: usage: outbreak contract"));

    Ok(())
}
