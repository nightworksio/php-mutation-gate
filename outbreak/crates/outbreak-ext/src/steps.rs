//! The steps a stream's process takes (ADR-0147): each jump an op takes and
//! each call of a user function, which bound every loop and every
//! recursion. A parent shares one limit per child slot with the children it
//! forks; a child bound to a slot stops itself by `SIGXCPU` once its count
//! passes the limit the parent set there, which the parent reads as a
//! timeout. A slot no parent has limited holds no child back.

#![allow(unsafe_code, reason = "opcode handlers, shared memory and signals exist only behind C interfaces")]

use std::ffi::c_int;
use std::sync::atomic::{AtomicI64, AtomicPtr, AtomicU64, AtomicUsize, Ordering};

use ext_php_rs::ffi;

/// A user opcode handler, as the Zend engine calls it.
type Handler = unsafe extern "C" fn(*mut ffi::zend_execute_data) -> c_int;

unsafe extern "C" {
    fn zend_set_user_opcode_handler(opcode: u8, handler: Option<Handler>) -> c_int;
    fn zend_get_user_opcode_handler(opcode: u8) -> Option<Handler>;
}

/// The jumps every loop takes: `ZEND_JMP`, `ZEND_JMPZ` and `ZEND_JMPNZ`.
const JUMPS: [u8; 3] = [42, 43, 44];

/// What a handler answers to run the op as the engine would have.
const DISPATCH: c_int = 2;

/// What `zend_set_user_opcode_handler` answers once it set a handler.
const SUCCESS: c_int = 0;

/// The limit of a slot no parent has limited.
const UNLIMITED: i64 = i64::MAX;

static STEPS: AtomicU64 = AtomicU64::new(0);

static PREVIOUS: [AtomicUsize; JUMPS.len()] = [const { AtomicUsize::new(0) }; JUMPS.len()];

static SLOTS: AtomicPtr<AtomicI64> = AtomicPtr::new(std::ptr::null_mut());

static SLOT_COUNT: AtomicUsize = AtomicUsize::new(0);

/// The slot this child is bound to, plus one; none where it is zero.
static BOUND: AtomicUsize = AtomicUsize::new(0);

/// Counts each jump from the start of the process on; false where a jump's handler could not be set.
pub fn install() -> bool {
    JUMPS.iter().zip(&PREVIOUS).all(|(&opcode, previous)| {
        // SAFETY: called once at module startup, before any op array is compiled.
        let before = unsafe { zend_get_user_opcode_handler(opcode) };
        previous.store(before.map_or(0, |handler| handler as usize), Ordering::Relaxed);

        // SAFETY: as above.
        unsafe { zend_set_user_opcode_handler(opcode, Some(jumped)) == SUCCESS }
    })
}

/// A user function was called.
pub fn stepped() {
    let steps = STEPS.fetch_add(1, Ordering::Relaxed) + 1;
    held(steps);
}

/// The steps this process took since it started, its parent's among them in a child.
#[must_use]
pub fn taken() -> i64 {
    i64::try_from(STEPS.load(Ordering::Relaxed)).unwrap_or(UNLIMITED)
}

/// Shares this many slots with the children this process forks, each unlimited; false where they cannot be shared.
#[must_use]
pub fn shared(count: usize) -> bool {
    if !SLOTS.load(Ordering::Relaxed).is_null() {
        return SLOT_COUNT.load(Ordering::Relaxed) >= count;
    }

    let Some(length) = count.checked_mul(size_of::<AtomicI64>()).filter(|&length| length > 0) else {
        return false;
    };

    let slots = mapped(length);

    if slots.is_null() {
        return false;
    }

    for index in 0..count {
        // SAFETY: inside the mapping, aligned as the page is.
        unsafe { slots.add(index).write(AtomicI64::new(UNLIMITED)) };
    }

    SLOT_COUNT.store(count, Ordering::Relaxed);
    SLOTS.store(slots, Ordering::Release);

    true
}

/// Sets the most steps a child bound to this slot may count; false where there is no such slot.
#[must_use]
pub fn limited(slot: usize, most: i64) -> bool {
    slot_of(slot).is_some_and(|limit| {
        limit.store(most, Ordering::Relaxed);
        true
    })
}

/// Binds this process, a child, to a slot; false where there is no such slot.
#[must_use]
pub fn bound(slot: usize) -> bool {
    slot_of(slot).is_some_and(|_| {
        BOUND.store(slot + 1, Ordering::Relaxed);
        true
    })
}

fn slot_of(slot: usize) -> Option<&'static AtomicI64> {
    let slots = SLOTS.load(Ordering::Acquire);

    if slots.is_null() || slot >= SLOT_COUNT.load(Ordering::Relaxed) {
        return None;
    }

    // SAFETY: inside the mapping, which lives as long as the process.
    Some(unsafe { &*slots.add(slot) })
}

/// Stops a bound child past its slot's limit.
fn held(steps: u64) {
    let bound = BOUND.load(Ordering::Relaxed);

    if bound == 0 {
        return;
    }

    let past = slot_of(bound - 1)
        .is_some_and(|limit| i64::try_from(steps).unwrap_or(UNLIMITED) > limit.load(Ordering::Relaxed));

    if past {
        stopped();
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

    if mapped == libc::MAP_FAILED { std::ptr::null_mut() } else { mapped.cast() }
}

/// No pages are shared where no child is forked.
#[cfg(not(unix))]
fn mapped(_length: usize) -> *mut AtomicI64 {
    std::ptr::null_mut()
}

/// Ends this process by `SIGXCPU`, leaving no core behind.
#[cfg(unix)]
fn stopped() {
    let none = libc::rlimit { rlim_cur: 0, rlim_max: 0 };

    // SAFETY: plain system calls on this process alone.
    unsafe {
        libc::setrlimit(libc::RLIMIT_CORE, &raw const none);
        libc::signal(libc::SIGXCPU, libc::SIG_DFL);
        libc::raise(libc::SIGXCPU);
        libc::_exit(libc::EXIT_FAILURE);
    }
}

/// No child is bound where none is forked.
#[cfg(not(unix))]
fn stopped() {}

unsafe extern "C" fn jumped(execute_data: *mut ffi::zend_execute_data) -> c_int {
    stepped();

    // SAFETY: the engine calls a handler with the live frame of the op it runs.
    let opcode = unsafe { (*(*execute_data).opline).opcode };
    let before = JUMPS
        .iter()
        .position(|&jump| jump == opcode)
        .and_then(|index| PREVIOUS.get(index))
        .map_or(0, |previous| previous.load(Ordering::Relaxed));

    if before == 0 {
        return DISPATCH;
    }

    // SAFETY: the handler another extension set for this opcode before this one, kept as it was.
    let handler = unsafe { std::mem::transmute::<usize, Handler>(before) };

    // SAFETY: as the engine would have called it.
    unsafe { handler(execute_data) }
}
