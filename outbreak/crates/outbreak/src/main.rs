//! The `outbreak` binary: its arguments, standard streams and cores handed to
//! the command they name, and its failure said on the error stream.

use std::io;
use std::num::NonZero;
use std::process::ExitCode;
use std::thread;

fn main() -> ExitCode {
    let arguments: Vec<String> = std::env::args_os()
        .skip(1)
        .map(|argument| argument.to_string_lossy().into_owned())
        .collect();
    let cores = thread::available_parallelism().unwrap_or(NonZero::<usize>::MIN);

    match outbreak::run(
        &arguments,
        &mut io::stdin().lock(),
        &mut io::stdout().lock(),
        cores,
    ) {
        Ok(()) => ExitCode::SUCCESS,
        Err(failure) => {
            eprintln!("outbreak: {failure}");
            ExitCode::from(failure.code())
        }
    }
}
