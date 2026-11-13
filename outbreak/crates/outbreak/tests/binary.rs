//! The binary as the gate runs it: arguments, input and output streams, and the code it ends with.

use std::error::Error;
use std::io::Write;
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
        .map(|mut stdin| stdin.write_all(input))
        .transpose()?;
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

#[test]
fn starts_a_daemon_of_its_own_that_stops_once_idle() -> Result<(), Box<dyn Error>> {
    let root = std::env::temp_dir().join(format!("outbreak-detach-{}", std::process::id()));
    std::fs::create_dir_all(&root)?;
    let at = root.to_string_lossy().into_owned();
    let (code, said, _) = ran(&["serve", &at, "--detach", "--idle", "0.2"], b"")?;
    let euid = std::os::unix::fs::MetadataExt::uid(&std::fs::metadata(&root)?);
    let socket = outbreak_serve::server::socket(&root, euid)?;
    let listened = (0..100).any(|_| {
        std::thread::sleep(std::time::Duration::from_millis(20));
        socket.exists()
    });
    let stopped = (0..200).any(|_| {
        std::thread::sleep(std::time::Duration::from_millis(20));
        !socket.exists()
    });

    assert_eq!(code, Some(0));
    assert!(said.starts_with("{\"outbreak\":1,\"started\":"), "{said}");
    assert!(listened && stopped);
    assert!(root.join(".mutation-gate/outbreak/state.bin").exists());
    std::fs::remove_dir_all(&root)?;

    Ok(())
}

#[test]
fn serves_an_editor_on_its_streams_and_says_why_it_cannot() -> Result<(), Box<dyn Error>> {
    let exit = r#"{"jsonrpc":"2.0","method":"exit"}"#;
    let framed = format!("Content-Length: {}\r\n\r\n{exit}", exit.len());
    let (code, said, errors) = ran(&["lsp", "--stdio"], framed.as_bytes())?;

    assert_eq!(code, Some(1));
    assert_eq!(said, "");
    assert!(errors.starts_with("outbreak: the editor left without asking the server to shut down"));

    let (code, _, errors) = ran(&["lsp", "--port"], b"")?;

    assert_eq!(code, Some(2));
    assert!(errors.starts_with("outbreak: lsp takes no --port"));

    Ok(())
}
