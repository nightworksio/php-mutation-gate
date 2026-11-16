//! The state a call leaves behind (ADR-0130, H5): a split stream arms a
//! watch on the call that reaches a mutant's site as it forks, in the parent
//! and in the child alike, and each reads, once that call returns, one digest
//! of what it returned and of the properties of the object it ran on. Two
//! equal digests say the fork changed neither; the stream counts them.
//! Static properties, globals and the state an internal object keeps out of
//! its properties are not read, so the count is a measurement, which no
//! verdict reads.

#![allow(
    unsafe_code,
    reason = "frames and values exist only behind the Zend engine's own pointers"
)]

use std::cell::RefCell;
use std::collections::HashMap;
use std::sync::OnceLock;

use ext_php_rs::ffi;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ExecuteData, FcallInfo, FcallObserver};

use crate::canon::Canon;
use crate::value::{self, Seen};
use crate::{engine, touches};

/// The variable that says what a stream's process observes: `states`, `touches`, `lines` and `steps`, by commas.
const OBSERVE: &str = "MUTATION_GATE_OBSERVE";

/// A user function, as `zend_function.type` says it.
const USER_FUNCTION: u8 = 2;

/// What the process observes, read once from its environment.
#[expect(
    clippy::struct_excessive_bools,
    reason = "each is a switch of its own the environment sets"
)]
pub struct Observing {
    states: bool,
    touches: bool,
    /// Whether the process records the lines it executes.
    pub lines: bool,
    /// Whether the process counts its steps.
    pub steps: bool,
}

/// What the process observes.
pub fn observing() -> &'static Observing {
    static OBSERVING: OnceLock<Observing> = OnceLock::new();

    OBSERVING.get_or_init(|| {
        let asked = std::env::var(OBSERVE).unwrap_or_default();
        let has = |part: &str| asked.split(',').any(|item| item == part);

        Observing {
            states: has("states"),
            touches: has("touches"),
            lines: has("lines"),
            steps: has("steps"),
        }
    })
}

/// The most values one digest reads; a state larger than this has no digest.
const VALUES: usize = 10_000;

/// An object, as a frame's `This` holds it.
const OBJECT: u8 = 8;

#[derive(Default)]
struct Watches {
    next: i64,
    armed: HashMap<usize, i64>,
    read: HashMap<i64, String>,
}

thread_local! {
    static WATCHES: RefCell<Watches> = RefCell::new(Watches::default());
}

/// The observer that reads each watched call's state as it returns.
pub struct Watch;

impl FcallObserver for Watch {
    fn should_observe(&self, info: &FcallInfo) -> bool {
        if info.is_internal {
            observing().touches && touches::observes(info)
        } else {
            observing().states || observing().steps
        }
    }

    fn begin(&self, execute_data: &ExecuteData) {
        // SAFETY: a frame's function is live while it runs.
        if unsafe { (*execute_data.func).type_ } == USER_FUNCTION {
            engine::stepped();
        } else {
            engine::called(execute_data);
        }
    }

    fn end(&self, execute_data: &ExecuteData, retval: Option<&Zval>) {
        let frame = std::ptr::from_ref(execute_data) as usize;
        let slot = WATCHES.with(|watches| watches.borrow_mut().armed.remove(&frame));

        if let Some(slot) = slot
            && let Some(digest) = digest(execute_data, retval)
        {
            WATCHES.with(|watches| watches.borrow_mut().read.insert(slot, digest));
        }
    }
}

/// Arms a watch on the nearest calling frame whose class is outside this
/// namespace: its slot, or none where every frame is inside it.
#[must_use]
pub fn armed(skipping: &str) -> Option<i64> {
    // SAFETY: the executor globals live as long as the request.
    let mut frame = unsafe { (*ffi::ext_php_rs_executor_globals()).current_execute_data };

    while !frame.is_null() {
        // SAFETY: a live frame of the running call stack.
        let (function, previous) = unsafe { ((*frame).func, (*frame).prev_execute_data) };

        // SAFETY: a frame's function is live while the frame is.
        if !function.is_null() && unsafe { is_watchable(function, skipping) } {
            return Some(WATCHES.with(|watches| {
                let mut watches = watches.borrow_mut();
                watches.next += 1;
                let slot = watches.next;
                watches.armed.insert(frame as usize, slot);
                slot
            }));
        }

        frame = previous;
    }

    None
}

/// The digest a watch read once its call returned, taken; none before it returned, or where its state was too
/// large to read, which a stream counts as changed.
#[must_use]
pub fn taken(slot: i64) -> Option<String> {
    WATCHES.with(|watches| watches.borrow_mut().read.remove(&slot))
}

/// Whether a frame's function is a user function outside the namespace.
unsafe fn is_watchable(function: *const ffi::zend_function, skipping: &str) -> bool {
    // SAFETY: the members common to every function.
    let (kind, scope) = unsafe { ((*function).type_, (*function).common.scope) };

    if u32::from(kind) != ffi::ZEND_USER_FUNCTION {
        return false;
    }

    // SAFETY: a scope is a live class or none.
    let class = if scope.is_null() {
        None
    } else {
        unsafe { value::text((*scope).name) }
    };

    class.is_none_or(|name| !name.starts_with(skipping.as_bytes()))
}

fn digest(execute_data: &ExecuteData, retval: Option<&Zval>) -> Option<String> {
    let mut canon = Canon::new();
    let mut seen = Seen::within(VALUES);
    canon.section("return");
    // SAFETY: the value the call returned, live while its end is observed.
    let returned = retval.map_or(Some(()), |retval| unsafe {
        value::state(&mut canon, retval, &mut seen)
    });
    canon.section("this");
    let this = &raw const execute_data.This;
    // SAFETY: `This` holds an object where the call ran on one.
    let on = if unsafe { (*this).u1.v.type_ } == OBJECT {
        // SAFETY: as above.
        unsafe { value::state(&mut canon, this, &mut seen) }
    } else {
        Some(())
    };

    returned.and(on).map(|()| canon.digest())
}
