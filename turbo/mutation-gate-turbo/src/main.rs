//! `mutation-gate-turbo handshake` says what the helper is; `mutation-gate-turbo answer <file>` answers the one
//! request that file holds, on standard output. A refusal is written to standard error with exit code 2.

use mutation_gate_turbo::protocol::{answer, handshake};
use std::io::Write;
use std::process::ExitCode;
use std::thread;

fn main() -> ExitCode {
    let arguments: Vec<String> = std::env::args().skip(1).collect();
    let outcome = match arguments.as_slice() {
        [asked] if asked == "handshake" => Ok(handshake().to_string()),
        [asked, file] if asked == "answer" => answered(file),
        _ => Err(String::from(
            "usage: mutation-gate-turbo handshake | answer <request file>",
        )),
    };

    match outcome {
        Ok(text) => written(&mut std::io::stdout(), &text),
        Err(why) => {
            written(&mut std::io::stderr(), &why);

            ExitCode::from(2)
        }
    }
}

fn answered(file: &str) -> Result<String, String> {
    let request =
        std::fs::read_to_string(file).map_err(|error| format!("the request could not be read from {file}: {error}"))?;
    let threads = thread::available_parallelism().map_or(1, std::num::NonZero::get);

    answer(&request, threads)
        .map(|value| value.to_string())
        .map_err(|refusal| refusal.why().to_owned())
}

fn written(stream: &mut impl Write, text: &str) -> ExitCode {
    match writeln!(stream, "{text}") {
        Ok(()) => ExitCode::SUCCESS,
        Err(_) => ExitCode::from(2),
    }
}
