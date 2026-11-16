//! Whether a forked child's test touched a source of nondeterminism after
//! the fork (ADR-0130): the clock, randomness, the environment, another
//! process, the network, or a file outside the project's tree. A kill by
//! such a child is judged again by the mutant's own run. Nothing is ever
//! skipped because a test touched none: the observer cannot see every
//! source, such as randomness inside an extension's own C code.

use std::cell::RefCell;

use ext_php_rs::zend::FcallInfo;

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

/// A call of a watched function, as its frame names it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Call<'a> {
    /// The function's name.
    pub name: &'a [u8],
    /// Whether the function is a method of a class.
    pub scoped: bool,
    /// The first argument, where it is a string.
    pub first: Option<&'a [u8]>,
}

/// A watched function was called; its call is read only while watching.
pub fn called<'a>(call: impl FnOnce() -> Call<'a>) {
    WATCH.with(|watch| {
        let mut watch = watch.borrow_mut();
        let touched = watch
            .root
            .as_deref()
            .is_some_and(|root| touches(&call(), root));
        watch.touched |= touched;
    });
}

/// Whether a call touches nondeterminism: every one does but a call naming a file inside the project.
fn touches(call: &Call<'_>, root: &[u8]) -> bool {
    call.scoped
        || !FILES.iter().any(|file| file.as_bytes() == call.name)
        || call.first.is_some_and(|path| outside(path, root))
}

/// Whether a path lies outside the project: a stream's URL, or an absolute path not under the root.
fn outside(path: &[u8], root: &[u8]) -> bool {
    let url = path.windows(3).any(|part| part == b"://");
    let absolute = path.first() == Some(&b'/');

    url || (absolute && !path.starts_with(root))
}

#[cfg(test)]
mod tests {
    use ext_php_rs::zend::FcallInfo;

    use super::{Call, called, observes, start, touched};

    fn info<'a>(class: Option<&'a str>, function: Option<&'a str>) -> FcallInfo<'a> {
        FcallInfo {
            function_name: function,
            class_name: class,
            filename: None,
            lineno: 0,
            is_internal: true,
        }
    }

    fn call<'a>(name: &'a str, first: Option<&'a str>) -> Call<'a> {
        Call {
            name: name.as_bytes(),
            scoped: false,
            first: first.map(str::as_bytes),
        }
    }

    fn made<'a>(call: Call<'a>) -> impl FnOnce() -> Call<'a> {
        move || call
    }

    fn touches(call: Call<'_>) -> bool {
        start("/project");
        called(made(call));

        touched()
    }

    #[test]
    fn observes_the_functions_that_can_touch_nondeterminism() {
        assert!(observes(&info(None, Some("time"))));
        assert!(observes(&info(None, Some("fopen"))));
        assert!(!observes(&info(None, Some("strlen"))));
        assert!(!observes(&info(None, None)));
        assert!(observes(&info(Some("Random\\Randomizer"), Some("getInt"))));
        assert!(observes(&info(Some("DateTime"), Some("__construct"))));
        assert!(!observes(&info(Some("DateTime"), Some("format"))));
        assert!(!observes(&info(Some("ArrayObject"), Some("__construct"))));
    }

    #[test]
    fn touches_by_every_call_but_one_naming_a_file_in_the_project() {
        assert!(touches(call("time", None)));
        assert!(touches(Call {
            scoped: true,
            ..call("fopen", Some("/project/a"))
        }));
        assert!(!touches(call("fopen", Some("/project/a"))));
        assert!(!touches(call("fopen", Some("relative/a"))));
        assert!(!touches(call("fopen", None)));
        assert!(touches(call("fopen", Some("/etc/hosts"))));
        assert!(touches(call("file_get_contents", Some("php://stdin"))));
    }

    #[test]
    fn touches_nothing_before_watching_starts_and_afresh_once_it_does() {
        called(made(call("rand", None)));

        assert!(!touched());
        assert!(touches(call("rand", None)));

        start("/project");

        assert!(!touched());
    }
}
