//! The lines a stream's parent executes (ADR-0147): the extension takes
//! PHP's executor over at startup, as pcov does, and while recording, keeps
//! the line of each op a user function executes, by file. Where another
//! extension took the executor over after it, nothing is recorded.

#![allow(
    unsafe_code,
    reason = "the executor and its frames exist only behind the Zend engine's own pointers"
)]

use std::cell::RefCell;
use std::collections::HashMap;
use std::ffi::c_int;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

use ext_php_rs::ffi;

use crate::value;

/// The executor a frame of user code runs in.
type Execute = unsafe extern "C" fn(*mut ffi::zend_execute_data);

unsafe extern "C" {
    static mut zend_execute_ex: Option<Execute>;
    fn zend_vm_call_opcode_handler(execute_data: *mut ffi::zend_execute_data) -> c_int;
}

/// What an op's handler answers where the frame goes on as it is.
const SUCCESS: c_int = 0;

/// The bits of one word of a file's lines.
const WORD: u32 = u64::BITS;

#[derive(Default)]
struct Recording {
    last: usize,
    current: Option<usize>,
    names: HashMap<Vec<u8>, usize>,
    files: Vec<(Vec<u8>, Vec<u64>)>,
}

static PREVIOUS: AtomicUsize = AtomicUsize::new(0);

static ON: AtomicBool = AtomicBool::new(false);

thread_local! {
    static RECORDING: RefCell<Recording> = RefCell::new(Recording::default());
}

/// Takes the executor over from the start of the process on.
pub fn install() {
    // SAFETY: called once at module startup, before any user code runs.
    let previous = unsafe { zend_execute_ex };
    PREVIOUS.store(
        previous.map_or(0, |execute| execute as usize),
        Ordering::Relaxed,
    );

    // SAFETY: as above.
    unsafe { zend_execute_ex = Some(executed) };
}

/// Hands the executor back to the one this extension took it from, where it still holds it.
pub fn uninstall() {
    let previous = PREVIOUS.swap(0, Ordering::Relaxed);

    if previous == 0 || !holds() {
        return;
    }

    // SAFETY: the executor kept at startup, called at module shutdown, after the last user code ran.
    unsafe { zend_execute_ex = Some(std::mem::transmute::<usize, Execute>(previous)) };
}

/// Whether this extension holds the executor.
fn holds() -> bool {
    // SAFETY: the executor is read where no other thread runs PHP.
    unsafe { zend_execute_ex }
        .is_some_and(|execute| std::ptr::fn_addr_eq(execute, executed as Execute))
}

/// Starts recording afresh; false where this extension does not hold the executor.
#[must_use]
pub fn start() -> bool {
    let holds = holds();

    RECORDING.with(|recording| *recording.borrow_mut() = Recording::default());
    ON.store(holds, Ordering::Relaxed);

    holds
}

/// Stops recording: the lines executed since it started, in order, by file.
#[must_use]
pub fn stop() -> HashMap<String, Vec<i64>> {
    ON.store(false, Ordering::Relaxed);
    let recording = RECORDING.with(|recording| std::mem::take(&mut *recording.borrow_mut()));

    recording
        .files
        .into_iter()
        .map(|(name, words)| {
            (
                String::from_utf8_lossy(&name).into_owned(),
                lines_of(&words),
            )
        })
        .collect()
}

fn lines_of(words: &[u64]) -> Vec<i64> {
    let mut lines = Vec::new();

    for (index, &word) in words.iter().enumerate() {
        let mut rest = word;

        while rest != 0 {
            let bit = rest.trailing_zeros();
            rest &= rest - 1;
            lines.push(
                i64::try_from(index)
                    .unwrap_or(i64::MAX)
                    .saturating_mul(i64::from(WORD))
                    + i64::from(bit),
            );
        }
    }

    lines
}

unsafe extern "C" fn executed(mut execute_data: *mut ffi::zend_execute_data) {
    loop {
        // SAFETY: the engine runs a live frame of user code here.
        unsafe { recorded(execute_data) };

        // SAFETY: as above.
        let code = unsafe { zend_vm_call_opcode_handler(execute_data) };

        if code != SUCCESS {
            if code < SUCCESS {
                return;
            }

            // SAFETY: the executor globals live as long as the request.
            execute_data = unsafe { (*ffi::ext_php_rs_executor_globals()).current_execute_data };
        }
    }
}

unsafe fn recorded(execute_data: *const ffi::zend_execute_data) {
    if !ON.load(Ordering::Relaxed) {
        return;
    }

    RECORDING.with(|recording| {
        let mut recording = recording.borrow_mut();

        // SAFETY: a live frame of user code, its function and op.
        let (file, line) = unsafe {
            (
                (*(*execute_data).func).op_array.filename,
                (*(*execute_data).opline).lineno,
            )
        };

        let index = if file as usize == recording.last {
            recording.current
        } else {
            // SAFETY: a user function's file name is a live string.
            let found = unsafe { value::text(file) }.map(|name| recording.indexed(name));
            recording.last = file as usize;
            recording.current = found;
            found
        };

        if let Some((_, words)) = index.and_then(|index| recording.files.get_mut(index)) {
            marked(words, line);
        }
    });
}

impl Recording {
    fn indexed(&mut self, name: &[u8]) -> usize {
        if let Some(&index) = self.names.get(name) {
            return index;
        }

        let index = self.files.len();
        self.names.insert(name.to_vec(), index);
        self.files.push((name.to_vec(), Vec::new()));

        index
    }
}

fn marked(words: &mut Vec<u64>, line: u32) {
    let at = usize::try_from(line / WORD).unwrap_or(usize::MAX);

    if at >= words.len() {
        words.resize(at.saturating_add(1), 0);
    }

    if let Some(word) = words.get_mut(at) {
        *word |= 1 << (line % WORD);
    }
}
