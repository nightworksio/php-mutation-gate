//! The binary's commands where they do not run: off Unix.

use crate::Failure;

/// `outbreak serve` serves on Unix alone.
pub(crate) fn served(_: &[String]) -> Result<String, Failure> {
    Err(Failure::Usage("serve runs on Unix alone".to_owned()))
}
