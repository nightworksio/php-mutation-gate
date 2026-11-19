//! The steps a stream's process takes (ADR-0147): each jump an op takes and
//! each call of a user function, which bound every loop and every
//! recursion. A parent shares one limit per child slot with the children it
//! forks; a child bound to a slot is past its limit once its count passes
//! the limit the parent set there, and stops itself by `SIGXCPU`, which the
//! parent reads as a timeout. A slot no parent has limited holds no child
//! back.

#![allow(
    unsafe_code,
    reason = "shared memory exists only behind mmap, and its slots behind its pointer"
)]

use std::sync::atomic::{AtomicI64, AtomicPtr, AtomicU64, AtomicUsize, Ordering};

/// The limit of a slot no parent has limited.
const UNLIMITED: i64 = i64::MAX;

/// The steps this process takes.
pub static STEPS: Steps = Steps::fresh();

/// The steps a process took, and the limits it shares with the children it forks.
pub struct Steps {
    taken: AtomicU64,
    slots: AtomicPtr<AtomicI64>,
    count: AtomicUsize,
    /// The slot this child is bound to, plus one; none where it is zero.
    bound: AtomicUsize,
}

impl Steps {
    /// No step taken, no slot shared, and bound to none.
    #[must_use]
    pub const fn fresh() -> Self {
        Self {
            taken: AtomicU64::new(0),
            slots: AtomicPtr::new(std::ptr::null_mut()),
            count: AtomicUsize::new(0),
            bound: AtomicUsize::new(0),
        }
    }

    /// One more step: whether this process, bound to a slot, is past its limit now.
    #[must_use]
    pub fn stepped(&self) -> bool {
        let steps = self.taken.fetch_add(1, Ordering::Relaxed) + 1;

        self.bound
            .load(Ordering::Relaxed)
            .checked_sub(1)
            .and_then(|slot| self.slot_of(slot))
            .is_some_and(|limit| {
                i64::try_from(steps).unwrap_or(UNLIMITED) > limit.load(Ordering::Relaxed)
            })
    }

    /// The steps taken since the process started, its parent's among them in a child.
    #[must_use]
    pub fn taken(&self) -> i64 {
        i64::try_from(self.taken.load(Ordering::Relaxed)).unwrap_or(UNLIMITED)
    }

    /// Shares this many slots with the children this process forks, each unlimited; false where they cannot be
    /// shared.
    #[must_use]
    pub fn shared(&self, count: usize) -> bool {
        if !self.slots.load(Ordering::Relaxed).is_null() {
            return self.count.load(Ordering::Relaxed) >= count;
        }

        let slots = count
            .checked_mul(size_of::<AtomicI64>())
            .filter(|&length| length > 0)
            .map_or(std::ptr::null_mut(), mapped);

        if slots.is_null() {
            return false;
        }

        for index in 0..count {
            // SAFETY: inside the mapping, aligned as the page is.
            unsafe { slots.add(index).write(AtomicI64::new(UNLIMITED)) };
        }

        self.count.store(count, Ordering::Relaxed);
        self.slots.store(slots, Ordering::Release);

        true
    }

    /// Sets the most steps a child bound to this slot may count; false where there is no such slot.
    #[must_use]
    pub fn limited(&self, slot: usize, most: i64) -> bool {
        self.slot_of(slot).is_some_and(|limit| {
            limit.store(most, Ordering::Relaxed);
            true
        })
    }

    /// Binds this process, a child, to a slot; false where there is no such slot.
    #[must_use]
    pub fn bound(&self, slot: usize) -> bool {
        self.slot_of(slot).is_some_and(|_| {
            self.bound.store(slot + 1, Ordering::Relaxed);
            true
        })
    }

    fn slot_of(&self, slot: usize) -> Option<&AtomicI64> {
        let slots = self.slots.load(Ordering::Acquire);

        (!slots.is_null() && slot < self.count.load(Ordering::Relaxed))
            // SAFETY: inside the mapping, which lives as long as the process.
            .then(|| unsafe { &*slots.add(slot) })
    }
}

/// Fresh pages shared with every child forked after they were mapped; none where they cannot be.
#[cfg(unix)]
fn mapped(length: usize) -> *mut AtomicI64 {
    // SAFETY: an anonymous mapping of fresh pages.
    let mapped = unsafe {
        libc::mmap(
            std::ptr::null_mut(),
            length,
            libc::PROT_READ | libc::PROT_WRITE,
            libc::MAP_SHARED | libc::MAP_ANON,
            -1,
            0,
        )
    };

    if mapped == libc::MAP_FAILED {
        std::ptr::null_mut()
    } else {
        mapped.cast()
    }
}

/// No pages are shared where no child is forked.
#[cfg(not(unix))]
fn mapped(_length: usize) -> *mut AtomicI64 {
    std::ptr::null_mut()
}

#[cfg(test)]
mod tests {
    use super::{Steps, UNLIMITED};

    #[test]
    fn counts_each_step() {
        let steps = Steps::fresh();

        assert!(!steps.stepped());
        assert!(!steps.stepped());
        assert_eq!(steps.taken(), 2);
    }

    #[test]
    fn holds_a_bound_child_to_its_slot_s_limit() {
        let steps = Steps::fresh();

        assert!(!steps.limited(0, 1));
        assert!(!steps.bound(0));
        assert!(steps.shared(2));
        assert!(steps.shared(2));
        assert!(!steps.shared(3));
        assert!(!steps.bound(2));
        assert!(steps.bound(1));
        assert!(!steps.stepped());
        assert!(steps.limited(1, 2));
        assert!(!steps.stepped());
        assert!(steps.stepped());
        assert!(!steps.limited(2, UNLIMITED));
    }

    #[test]
    fn shares_no_slot_it_cannot_map() {
        assert!(!Steps::fresh().shared(0));
        assert!(!Steps::fresh().shared(usize::MAX));
        assert!(!Steps::fresh().shared(usize::MAX / size_of::<u64>()));
    }
}
