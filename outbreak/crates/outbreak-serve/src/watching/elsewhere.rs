//! A watch where the platform has no notifications the daemon reads.

use std::collections::BTreeSet;
use std::path::{Path, PathBuf};

use super::Notices;

pub(super) fn native(_: &BTreeSet<PathBuf>, _: &Path) -> Result<Box<dyn Notices>, String> {
    Err(String::from(
        "this platform has no notifications the daemon watches through",
    ))
}
