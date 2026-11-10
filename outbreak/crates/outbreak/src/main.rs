//! The `outbreak` binary: its arguments, standard streams and cores handed to
//! the command they name, `lsp` given the streams themselves, and its failure
//! said on the error stream.

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
    let ran = match arguments.split_first() {
        Some((command, rest)) if command == outbreak::LSP => outbreak::lsp(rest),
        _ => outbreak::run(&arguments, &mut io::stdin().lock(), &mut io::stdout().lock(), cores),
    };

    match ran {
        Ok(()) => ExitCode::SUCCESS,
        Err(failure) => {
            eprintln!("outbreak: {failure}");
            ExitCode::from(failure.code())
        }
    }
}
