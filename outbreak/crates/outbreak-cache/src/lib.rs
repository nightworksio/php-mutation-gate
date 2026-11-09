//! The packs of proofs `outbreak cache` keeps by their keys (ADR-0131): read
//! within the gate's limits, merged by key, and moved between the gate's
//! directory and their store, the Actions cache or an OCI registry.
//!
//! A pack is a gzipped text of lines. The first names the pack:
//! `{"outbreak":1,"pack":"proofs","class":<class>,"base":<base>}`. Every other
//! line is one entry, `{"key":<key>,"ledger":<text>}`, whose ledger it never
//! reads: a key is the whole question, so two entries under one key answer it
//! alike, and the newer is kept.

pub mod actions;
pub mod oci;
pub mod pack;
pub mod request;

use std::fmt;

/// Why a command of the cache could not run.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Failure {
    /// The request or a pack is not one the cache reads.
    Refused(String),
    /// A file, a stream or the store could not be read or written.
    Io(String),
}

impl fmt::Display for Failure {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Refused(why) | Self::Io(why) => formatter.write_str(why),
        }
    }
}

/// Every pack the request names pulled into its path from its store: how many were.
///
/// # Errors
///
/// Fails where the request cannot be read; a pack the store does not hold is not pulled.
pub fn pull(text: &[u8]) -> Result<usize, Failure> {
    let request = request::read(text)?;

    match request.store {
        request::Store::Actions => actions::pull(&request),
        request::Store::Oci => oci::pull(&request),
    }
}

/// The entries at each path the request names merged into their class's pack in the store: how many packs were.
///
/// # Errors
///
/// Fails where the request, the entries or the store cannot be read, or the store cannot be written.
pub fn push(text: &[u8]) -> Result<usize, Failure> {
    let request = request::read(text)?;

    match request.store {
        request::Store::Actions => actions::push(&request),
        request::Store::Oci => oci::push(&request),
    }
}
