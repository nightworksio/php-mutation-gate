//! Whether a forked child's test touched a source of nondeterminism after
//! the fork (ADR-0130): the clock, randomness, the environment, another
//! process, the network, or a file outside the project's tree. A kill by
//! such a child is judged again by the mutant's own run. Nothing is ever
//! skipped because a test touched none: the observer cannot see every
//! source, such as randomness inside an extension's own C code.

#![allow(
    unsafe_code,
    reason = "a call's function and arguments exist only behind the Zend engine's own pointers"
)]

use std::cell::RefCell;

use ext_php_rs::ffi;
use ext_php_rs::zend::{ExecuteData, FcallInfo};

use crate::value;

/// Functions whose every call reads the clock, randomness, the environment, another process or the network.
const ALWAYS: &[&str] = &[
    "time",
    "microtime",
    "hrtime",
    "date",
    "gmdate",
    "mktime",
    "gmmktime",
    "strtotime",
    "getdate",
    "localtime",
    "idate",
    "gettimeofday",
    "date_create",
    "date_create_immutable",
    "sleep",
    "usleep",
    "time_nanosleep",
    "rand",
    "mt_rand",
    "random_int",
    "random_bytes",
    "uniqid",
    "lcg_value",
    "shuffle",
    "str_shuffle",
    "array_rand",
    "getenv",
    "gethostname",
    "getmypid",
    "sys_getloadavg",
    "memory_get_usage",
    "memory_get_peak_usage",
    "exec",
    "shell_exec",
    "system",
    "passthru",
    "proc_open",
    "popen",
    "fsockopen",
    "pfsockopen",
    "stream_socket_client",
    "curl_exec",
    "curl_multi_exec",
    "gethostbyname",
    "dns_get_record",
    "tmpfile",
];

/// Functions whose first argument names a file, which touch nondeterminism where it lies outside the project.
const FILES: &[&str] = &[
    "fopen",
    "file_get_contents",
    "file_put_contents",
    "file",
    "readfile",
    "opendir",
    "scandir",
    "glob",
    "is_file",
    "is_dir",
    "file_exists",
    "filemtime",
    "stat",
    "unlink",
    "rename",
    "mkdir",
    "touch",
    "tempnam",
];

/// Classes whose construction reads the clock.
const CLOCKS: &[&str] = &["DateTime", "DateTimeImmutable"];

/// The class every one of whose methods draws randomness.
const RANDOMIZER: &str = "Random\\Randomizer";

/// The first argument's slot past a frame, in values: the frame's own size, rounded up.
const FRAME: usize =
    std::mem::size_of::<ffi::zend_execute_data>().div_ceil(std::mem::size_of::<ffi::zval>());

#[derive(Default)]
struct Watch {
    root: Option<Vec<u8>>,
    touched: bool,
}

thread_local! {
    static WATCH: RefCell<Watch> = RefCell::new(Watch::default());
}

/// Whether the observer watches a function for touches.
#[must_use]
pub fn observes(info: &FcallInfo) -> bool {
    let Some(name) = info.function_name else {
        return false;
    };

    match info.class_name {
        Some(RANDOMIZER) => true,
        Some(class) => CLOCKS.contains(&class) && name == "__construct",
        None => ALWAYS.contains(&name) || FILES.contains(&name),
    }
}

/// Watching starts, for a project at this root: nothing is touched yet.
pub fn start(root: &str) {
    WATCH.with(|watch| {
        *watch.borrow_mut() = Watch {
            root: Some(root.as_bytes().to_vec()),
            touched: false,
        };
    });
}

/// Whether anything was touched since watching started.
#[must_use]
pub fn touched() -> bool {
    WATCH.with(|watch| watch.borrow().touched)
}

/// A watched function was called.
pub fn called(execute_data: &ExecuteData) {
    let root = WATCH.with(|watch| watch.borrow().root.clone());

    let Some(root) = root else {
        return;
    };

    // SAFETY: a live frame of an internal function the observer chose.
    if unsafe { touches(execute_data, &root) } {
        WATCH.with(|watch| watch.borrow_mut().touched = true);
    }
}

unsafe fn touches(execute_data: &ExecuteData, root: &[u8]) -> bool {
    // SAFETY: a frame's function is live while it runs.
    let function = unsafe { &*execute_data.func };
    // SAFETY: the members common to every function.
    let (name, scope) = unsafe { (function.common.function_name, function.common.scope) };
    // SAFETY: a function's name is a live string.
    let name = unsafe { value::text(name) }.unwrap_or_default();

    if !scope.is_null() || !FILES.iter().any(|file| file.as_bytes() == name) {
        return true;
    }

    // SAFETY: a frame counts its arguments in `This`'s second word.
    let count = unsafe { execute_data.This.u2.num_args };

    if count == 0 {
        return false;
    }

    // SAFETY: the first argument follows the frame, as ZEND_CALL_ARG lays it out.
    let first = unsafe {
        std::ptr::from_ref(execute_data)
            .cast::<ffi::zval>()
            .add(FRAME)
    };
    // SAFETY: a string argument is a live string; any other kind names no file.
    let path = unsafe {
        if (*first).u1.v.type_ == STRING {
            value::text((*first).value.str_)
        } else {
            None
        }
    };

    path.is_some_and(|path| outside(path, root))
}

/// Whether a path lies outside the project: a stream's URL, or an absolute path not under the root.
fn outside(path: &[u8], root: &[u8]) -> bool {
    let url = path.windows(3).any(|part| part == b"://");
    let absolute = path.first() == Some(&b'/');

    url || (absolute && !path.starts_with(root))
}

/// PHP's type of a string.
const STRING: u8 = 6;
