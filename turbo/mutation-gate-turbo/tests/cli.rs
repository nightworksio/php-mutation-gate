//! The binary as the gate runs it: a handshake, an answer to a request file, and refusals on standard error.

use std::process::Command;

fn helper() -> Command {
    Command::new(env!("CARGO_BIN_EXE_mutation-gate-turbo"))
}

fn written(name: &str, text: &str) -> std::path::PathBuf {
    let file = std::env::temp_dir().join(format!("mutation-gate-turbo-{}-{name}.json", std::process::id()));
    assert!(std::fs::write(&file, text).is_ok(), "the request file is written");

    file
}

#[test]
fn the_handshake_prints_the_name_version_and_protocol() {
    let ran = helper().arg("handshake").output();

    assert!(ran.as_ref().is_ok_and(|ran| ran.status.success()));
    assert_eq!(
        ran.map(|ran| String::from_utf8_lossy(&ran.stdout).trim().to_owned())
            .ok(),
        Some(format!(
            r#"{{"name":"mutation-gate-turbo","protocol":1,"version":"{}"}}"#,
            env!("CARGO_PKG_VERSION")
        ))
    );
}

#[test]
fn a_request_file_is_answered_on_standard_output() {
    let file = written(
        "answered",
        r#"{"protocol":1,"kind":"entry-keys","format":"f","base":"b","paths":["a"],"digests":["d"],"edges":[[]],"always":[],"entries":[[0]]}"#,
    );
    let ran = helper().arg("answer").arg(&file).output();
    let _ = std::fs::remove_file(&file);

    assert!(ran.as_ref().is_ok_and(|ran| ran.status.success()));
    assert!(
        ran.is_ok_and(|ran| String::from_utf8_lossy(&ran.stdout).starts_with(r#"{"keys":[""#)),
        "the answer holds the keys"
    );
}

#[test]
fn a_refusal_exits_2_with_the_reason_on_standard_error() {
    let file = written("refused", r#"{"protocol":9}"#);
    let refused = helper().arg("answer").arg(&file).output();
    let missing = helper().arg("answer").arg(file.with_extension("missing")).output();
    let unknown = helper().arg("serve").output();
    let _ = std::fs::remove_file(&file);

    for ran in [refused, missing, unknown] {
        assert!(ran.is_ok_and(|ran| ran.status.code() == Some(2) && !ran.stderr.is_empty() && ran.stdout.is_empty()));
    }
}
