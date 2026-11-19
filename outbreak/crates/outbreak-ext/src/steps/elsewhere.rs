//! The pages the steps' slots live in where no child is forked: off Unix.

use std::sync::atomic::AtomicI64;

/// No pages are shared where no child is forked.
pub(super) fn mapped(_length: usize) -> *mut AtomicI64 {
    std::ptr::null_mut()
}
