//! The power source where the platform tells the daemon of no change of it.

use crate::watching::Notices;

pub(crate) fn source() -> Result<Box<dyn Notices>, String> {
    Err(String::from(
        "this platform tells the daemon of no change of the power source",
    ))
}
