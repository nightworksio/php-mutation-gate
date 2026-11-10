//! `outbreak.so`, Outbreak's PHP extension (ADR-0130). It compiles a program
//! in the PHP that loaded it and answers one digest of its op arrays, so a
//! mutant whose program is its original's is proven equivalent before it
//! runs; and it reads the state a forked call leaves behind, so a split
//! stream counts the forks that changed nothing (H5); and it counts the
//! descriptors a forked child would share, so a stream forks no test that
//! holds one.

#![cfg_attr(windows, feature(abi_vectorcall))]
#![allow(missing_docs, reason = "php_module and php_function generate items the extension's own docs describe")]

pub mod canon;
mod descriptors;
mod program;
mod state;
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

/// The extension's version, which a proof's evidence names.
#[php_function]
#[must_use]
pub fn outbreak_version() -> String {
    env!("CARGO_PKG_VERSION").to_owned()
}

#[php_module]
pub fn module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(outbreak_op_arrays))
        .function(wrap_function!(outbreak_state_watch))
        .function(wrap_function!(outbreak_state_digest))
        .function(wrap_function!(outbreak_descriptors))
        .function(wrap_function!(outbreak_version))
        .fcall_observer(|| state::Watch)
}
