//! Computes mutation-gate's proof keys from what the gate hands it, byte for byte as the gate's PHP does.
//!
//! The gate's PHP is the definition. Every answer here must equal what the PHP would compute, or the gate
//! refuses the helper for the run.

pub mod entry_keys;
pub mod framing;
pub mod php_order;
pub mod protocol;
pub mod refusal;
