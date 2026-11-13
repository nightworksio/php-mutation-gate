//! The extension's hooks into the Zend engine, which run only inside the PHP
//! that loaded it: the executor it takes over to hand each op's line to
//! [`lines`], the jump handlers it sets to count [`steps`], the stop of a
//! child past its steps, and the frame of a watched call it hands to
//! [`touches`].

#![allow(
    unsafe_code,
    reason = "the executor, opcode handlers and frames exist only behind the Zend engine's own pointers"
)]

use std::ffi::c_int;
use std::sync::atomic::{AtomicUsize, Ordering};

use ext_php_rs::ffi;
use ext_php_rs::zend::ExecuteData;

use crate::{lines, steps, touches, value};

/// The executor a frame of user code runs in.
type Execute = unsafe extern "C" fn(*mut ffi::zend_execute_data);

/// A user opcode handler, as the Zend engine calls it.
type Handler = unsafe extern "C" fn(*mut ffi::zend_execute_data) -> c_int;

unsafe extern "C" {
    static mut zend_execute_ex: Option<Execute>;
    fn zend_vm_call_opcode_handler(execute_data: *mut ffi::zend_execute_data) -> c_int;
    fn zend_set_user_opcode_handler(opcode: u8, handler: Option<Handler>) -> c_int;
    fn zend_get_user_opcode_handler(opcode: u8) -> Option<Handler>;
}

/// What an op's handler answers where the frame goes on as it is.
const ONWARD: c_int = 0;

/// The jumps every loop takes: `ZEND_JMP`, `ZEND_JMPZ` and `ZEND_JMPNZ`.
const JUMPS: [u8; 3] = [42, 43, 44];

/// What a handler answers to run the op as the engine would have.
const DISPATCH: c_int = 2;

/// What `zend_set_user_opcode_handler` answers once it set a handler.
const SET: c_int = 0;

/// The first argument's slot past a frame, in values: the frame's own size, rounded up.
const FRAME: usize =
    std::mem::size_of::<ffi::zend_execute_data>().div_ceil(std::mem::size_of::<ffi::zval>());

/// PHP's type of a string.
const STRING: u8 = 6;

static EXECUTOR: AtomicUsize = AtomicUsize::new(0);

static HANDLERS: [AtomicUsize; JUMPS.len()] = [const { AtomicUsize::new(0) }; JUMPS.len()];

/// Takes the executor over from the start of the process on.
pub fn install_executor() {
    // SAFETY: called once at module startup, before any user code runs.
    let previous = unsafe { zend_execute_ex };
    EXECUTOR.store(
        previous.map_or(0, |execute| execute as usize),
        Ordering::Relaxed,
    );

    // SAFETY: as above.
    unsafe { zend_execute_ex = Some(executed) };
}

/// Hands the executor back to the one this extension took it from, where it still holds it.
pub fn uninstall_executor() {
    let previous = EXECUTOR.swap(0, Ordering::Relaxed);

    if previous == 0 || !holds_executor() {
        return;
    }

    // SAFETY: the executor kept at startup, called at module shutdown, after the last user code ran.
    unsafe { zend_execute_ex = Some(std::mem::transmute::<usize, Execute>(previous)) };
}

/// Whether this extension holds the executor.
#[must_use]
pub fn holds_executor() -> bool {
    // SAFETY: the executor is read where no other thread runs PHP.
    unsafe { zend_execute_ex }
        .is_some_and(|execute| std::ptr::fn_addr_eq(execute, executed as Execute))
}

unsafe extern "C" fn executed(mut execute_data: *mut ffi::zend_execute_data) {
    loop {
        // SAFETY: the engine runs a live frame of user code here.
        unsafe { recorded(execute_data) };

        // SAFETY: as above.
        let code = unsafe { zend_vm_call_opcode_handler(execute_data) };

        if code != ONWARD {
            if code < ONWARD {
                return;
            }

            // SAFETY: the executor globals live as long as the request.
            execute_data = unsafe { (*ffi::ext_php_rs_executor_globals()).current_execute_data };
        }
    }
}

unsafe fn recorded(execute_data: *const ffi::zend_execute_data) {
    if !lines::recording() {
        return;
    }

    // SAFETY: a live frame of user code, its function and op.
    let (file, line) = unsafe {
        (
            (*(*execute_data).func).op_array.filename,
            (*(*execute_data).opline).lineno,
        )
    };

    // SAFETY: a user function's file name is a live string.
    lines::ran(file as usize, || unsafe { value::text(file) }, line);
}

/// Counts each jump from the start of the process on; false where a jump's handler could not be set.
pub fn install_jumps() -> bool {
    JUMPS.iter().zip(&HANDLERS).all(|(&opcode, previous)| {
        // SAFETY: called once at module startup, before any op array is compiled.
        let before = unsafe { zend_get_user_opcode_handler(opcode) };
        previous.store(
            before.map_or(0, |handler| handler as usize),
            Ordering::Relaxed,
        );

        // SAFETY: as above.
        unsafe { zend_set_user_opcode_handler(opcode, Some(jumped)) == SET }
    })
}

/// A step was taken: a user function was called, or a jump.
pub fn stepped() {
    if steps::STEPS.stepped() {
        stopped();
    }
}

unsafe extern "C" fn jumped(execute_data: *mut ffi::zend_execute_data) -> c_int {
    stepped();

    // SAFETY: the engine calls a handler with the live frame of the op it runs.
    let opcode = unsafe { (*(*execute_data).opline).opcode };
    let before = JUMPS
        .iter()
        .position(|&jump| jump == opcode)
        .and_then(|index| HANDLERS.get(index))
        .map_or(0, |previous| previous.load(Ordering::Relaxed));

    if before == 0 {
        return DISPATCH;
    }

    // SAFETY: the handler another extension set for this opcode before this one, kept as it was.
    let handler = unsafe { std::mem::transmute::<usize, Handler>(before) };

    // SAFETY: as the engine would have called it.
    unsafe { handler(execute_data) }
}

/// Ends this process by `SIGXCPU`, leaving no core behind.
#[cfg(unix)]
fn stopped() {
    let none = libc::rlimit {
        rlim_cur: 0,
        rlim_max: 0,
    };

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

/// A watched internal function was called.
pub fn called(execute_data: &ExecuteData) {
    // SAFETY: a live frame of an internal function the observer chose.
    touches::called(|| unsafe { call(execute_data) });
}

/// The call a frame of an internal function makes.
unsafe fn call(execute_data: &ExecuteData) -> touches::Call<'_> {
    // SAFETY: a frame's function is live while it runs.
    let function = unsafe { &*execute_data.func };
    // SAFETY: the members common to every function.
    let (name, scope) = unsafe { (function.common.function_name, function.common.scope) };
    // SAFETY: a frame counts its arguments in `This`'s second word.
    let count = unsafe { execute_data.This.u2.num_args };
    // SAFETY: the first argument follows the frame, as ZEND_CALL_ARG lays it out, where there is one; a string
    // argument is a live string, and any other kind names no file.
    let first = (count > 0)
        .then(|| unsafe {
            let first = std::ptr::from_ref(execute_data)
                .cast::<ffi::zval>()
                .add(FRAME);

            if (*first).u1.v.type_ == STRING {
                value::text((*first).value.str_)
            } else {
                None
            }
        })
        .flatten();

    touches::Call {
        // SAFETY: a function's name is a live string.
        name: unsafe { value::text(name) }.unwrap_or_default(),
        scoped: !scope.is_null(),
        first,
    }
}
