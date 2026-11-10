//! `outbreak.so`, Outbreak's PHP extension (ADR-0130). It compiles a program
//! in the PHP that loaded it and answers one digest of its op arrays, so a
//! mutant whose program is its original's is proven equivalent before it
//! runs; and it reads the state a forked call leaves behind, so a split
//! stream counts the forks that changed nothing (H5); and it counts the
//! descriptors a forked child would share, so a stream forks no test that
//! holds one; and it sees a test touch a source of nondeterminism, so a
//! kill by such a test is judged again; and it records the lines a stream's
//! parent executes and counts the steps a stream's processes take, so a
//! stream measures its own coverage and holds a child to its parent's steps
//! (ADR-0147).

#![cfg_attr(windows, feature(abi_vectorcall))]
#![allow(missing_docs, reason = "php_module and php_function generate items the extension's own docs describe")]

pub mod canon;
mod descriptors;
mod lines;
mod program;
mod state;
mod steps;
mod touches;
mod value;

use ext_php_rs::prelude::*;

/// The digest of the op arrays of the program in this file, as this PHP
/// compiles it; null where it does not compile or holds a value no digest
/// reads.
#[php_function]
pub fn outbreak_op_arrays(file: &str) -> Option<String> {
    std::fs::read(file).ok().and_then(|source| program::digest(&source))
}

/// Arms a watch on the nearest calling frame whose class is outside this
/// namespace prefix; its slot, or null where there is none.
#[php_function]
pub fn outbreak_state_watch(skipping: &str) -> Option<i64> {
    state::armed(skipping)
}

/// The digest of the state the watched call left behind, taken; null before it returned.
#[php_function]
pub fn outbreak_state_digest(slot: i64) -> Option<String> {
    state::taken(slot)
}

/// The descriptors this process holds that a forked child would share with
/// it: regular files and pipes open for writing, then sockets, as counts.
#[php_function]
#[must_use]
pub fn outbreak_descriptors() -> Vec<i64> {
    let held = descriptors::held();

    vec![held.files, held.pipes, held.sockets]
}

/// Starts watching, for a project at this root, whether this process
/// touches the clock, randomness, the environment, another process, the
/// network or a file outside the root.
#[php_function]
pub fn outbreak_touches_start(root: &str) {
    touches::start(root);
}

/// Whether this process touched any of them since watching started.
#[php_function]
#[must_use]
pub fn outbreak_touched() -> bool {
    touches::touched()
}

/// Starts recording the lines this process executes, afresh; false where
/// it started without `lines` among what it observes, or another extension
/// took PHP's executor over.
#[php_function]
#[must_use]
pub fn outbreak_lines_start() -> bool {
    state::observing().lines && lines::start()
}

/// Stops recording: the lines executed since recording started, by file.
#[php_function]
#[must_use]
pub fn outbreak_lines_stop() -> std::collections::HashMap<String, Vec<i64>> {
    lines::stop()
}

/// The steps this process took since it started; null where it started without `steps` among what it observes.
#[php_function]
#[must_use]
pub fn outbreak_steps() -> Option<i64> {
    state::observing().steps.then(steps::taken)
}

/// Shares this many step limits with the children this process forks; false where they cannot be shared.
#[php_function]
#[must_use]
pub fn outbreak_steps_share(slots: i64) -> bool {
    state::observing().steps && usize::try_from(slots).is_ok_and(steps::shared)
}

/// Sets the most steps a child bound to this slot may count before it stops itself by `SIGXCPU`.
#[php_function]
#[must_use]
pub fn outbreak_steps_limit(slot: i64, most: i64) -> bool {
    usize::try_from(slot).is_ok_and(|slot| steps::limited(slot, most))
}

/// Binds this process, a forked child, to a slot's limit.
#[php_function]
#[must_use]
pub fn outbreak_steps_bind(slot: i64) -> bool {
    usize::try_from(slot).is_ok_and(steps::bound)
}

/// The extension's version, which a proof's evidence names.
#[php_function]
#[must_use]
pub fn outbreak_version() -> String {
    env!("CARGO_PKG_VERSION").to_owned()
}

#[php_module]
#[php(startup = started)]
pub fn module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(outbreak_op_arrays))
        .function(wrap_function!(outbreak_state_watch))
        .function(wrap_function!(outbreak_state_digest))
        .function(wrap_function!(outbreak_descriptors))
        .function(wrap_function!(outbreak_touches_start))
        .function(wrap_function!(outbreak_touched))
        .function(wrap_function!(outbreak_lines_start))
        .function(wrap_function!(outbreak_lines_stop))
        .function(wrap_function!(outbreak_steps))
        .function(wrap_function!(outbreak_steps_share))
        .function(wrap_function!(outbreak_steps_limit))
        .function(wrap_function!(outbreak_steps_bind))
        .function(wrap_function!(outbreak_version))
        .shutdown_function(stopped)
        .fcall_observer(|| state::Watch)
}

/// What module startup answers once it succeeded.
const STARTED: i32 = 0;

/// What module startup answers where a part could not start.
const FAILED: i32 = 1;

/// Takes PHP's executor over and counts jumps, as what the process observes asks.
fn started(_kind: i32, _module: i32) -> i32 {
    if state::observing().lines {
        lines::install();
    }

    if state::observing().steps && !steps::install() { FAILED } else { STARTED }
}

/// Hands PHP's executor back.
extern "C" fn stopped(_kind: i32, _module: i32) -> i32 {
    lines::uninstall();

    STARTED
}
