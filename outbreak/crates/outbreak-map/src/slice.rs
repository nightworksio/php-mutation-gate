//! A map's slice for some of its files, written as the gate writes the map
//! `MapText::onlyFor()` keeps: every test and what each process's tests loaded,
//! and the lines and methods of these files alone, in the order the map holds
//! them, with neither where the map was measured nor its entry keys.
//!
//! The slice copies the map's own bytes rather than writing them afresh, so it
//! is the gate's text only where the map is: one the gate wrote, compact, every
//! path as the gate's `Path` writes it. [`slicer`] refuses any other.

use std::collections::HashSet;

use crate::json::Refused;
use crate::map::{self, Entry, Shape, Unvouched};

/// A vouched map the gate wrote, ready to be sliced.
#[derive(Debug)]
pub struct Slicer<'a> {
    text: &'a [u8],
    shape: Shape,
}

/// A map's text read for slicing, where it is one the gate wrote.
///
/// # Errors
///
/// Says why a text is not sliced: it is not vouched for, holds whitespace between its tokens,
/// or writes a path otherwise than the gate's `Path` does.
pub fn slicer(text: &[u8]) -> Result<Slicer<'_>, Unvouched> {
    let shape = map::read(text)?;

    if shape.spaced {
        return Err(Refused {
            at: 0,
            expected: "a compact map, as the gate writes one",
        });
    }

    let unwritten = shape
        .files
        .iter()
        .chain(&shape.methods)
        .find(|entry| map::normalised(&entry.path) != entry.path);

    match unwritten {
        Some(entry) => Err(Refused {
            at: entry.key.start,
            expected: "a path as the gate writes it",
        }),
        None => Ok(Slicer { text, shape }),
    }
}

impl Slicer<'_> {
    /// The text of the map for these files alone.
    #[must_use]
    pub fn slice(&self, files: &HashSet<String>) -> Vec<u8> {
        let lines = kept(&self.shape.files, files);
        let methods = kept(&self.shape.methods, files);
        let mut text = Vec::with_capacity(self.shape.tests.len() + 64);

        text.extend_from_slice(b"{\"format\":1,\"tests\":");
        text.extend_from_slice(self.bytes(self.shape.tests.clone()));
        text.extend_from_slice(b",\"files\":");
        self.write(&mut text, &lines, b"{}");

        if !methods.is_empty() {
            text.extend_from_slice(b",\"methods\":");
            self.write(&mut text, &methods, b"");
        }

        let loads = self.shape.loads.clone().map(|loads| self.bytes(loads));

        if let Some(loads) = loads.filter(|loads| *loads != b"[]") {
            text.extend_from_slice(b",\"loads\":");
            text.extend_from_slice(loads);
        }

        text.push(b'}');

        text
    }

    /// Entries as PHP's `json_encode` writes an array keyed by their paths: a list where the
    /// keys PHP makes of them are 0, 1, 2 and on in order, an object otherwise, and `none` for
    /// no entry.
    fn write(&self, text: &mut Vec<u8>, entries: &[&Entry], none: &[u8]) {
        if entries.is_empty() {
            text.extend_from_slice(none);
            return;
        }

        let listed = entries
            .iter()
            .enumerate()
            .all(|(place, entry)| php_index(&entry.path) == i64::try_from(place).ok());
        let (open, close) = if listed { (b'[', b']') } else { (b'{', b'}') };
        text.push(open);

        for (place, entry) in entries.iter().enumerate() {
            if place > 0 {
                text.push(b',');
            }

            if !listed {
                text.extend_from_slice(self.bytes(entry.key.clone()));
                text.push(b':');
            }

            text.extend_from_slice(self.bytes(entry.value.clone()));
        }

        text.push(close);
    }

    fn bytes(&self, range: std::ops::Range<usize>) -> &[u8] {
        self.text.get(range).unwrap_or_default()
    }
}

/// The entries of these files alone, in the order the map holds them.
fn kept<'e>(entries: &'e [Entry], files: &HashSet<String>) -> Vec<&'e Entry> {
    entries
        .iter()
        .filter(|entry| files.contains(&entry.path))
        .collect()
}

/// The whole number PHP keys an array with for a string, where it keys it with one: `0`, or
/// digits with an optional leading minus and no leading nought, within PHP's integers.
fn php_index(key: &str) -> Option<i64> {
    let digits = key.strip_prefix('-').unwrap_or(key);
    let canonical = digits == "0" && key == "0"
        || digits.bytes().next().is_some_and(|first| first != b'0')
            && digits.bytes().all(|digit| digit.is_ascii_digit());

    if canonical { key.parse().ok() } else { None }
}

#[cfg(test)]
mod tests {
    use std::collections::HashSet;

    use super::{php_index, slicer};

    fn files(paths: &[&str]) -> HashSet<String> {
        paths.iter().map(|path| (*path).to_owned()).collect()
    }

    fn sliced(text: &str, paths: &[&str]) -> String {
        String::from_utf8(slicer(text.as_bytes()).unwrap().slice(&files(paths))).unwrap()
    }

    const MAP: &str = r#"{"format":1,"commit":"abc","dirty":false,"tests":[{"id":"a","seconds":0.5},{"id":"b"}],"files":{"src/A.php":{"3":[0,1],"4":[]},"src/B.php":{"1":[1]}},"methods":{"src/B.php":[{"name":"m","start":1,"end":2}]},"keys":{"x":"y"}}"#;

    #[test]
    fn keeps_every_test_and_the_lines_and_methods_of_the_files_asked_for() {
        assert_eq!(
            sliced(MAP, &["src/B.php", "src/C.php"]),
            r#"{"format":1,"tests":[{"id":"a","seconds":0.5},{"id":"b"}],"files":{"src/B.php":{"1":[1]}},"methods":{"src/B.php":[{"name":"m","start":1,"end":2}]}}"#
        );
    }

    #[test]
    fn keeps_the_files_in_the_order_the_map_holds_them_and_leaves_out_methods_it_has_none_of() {
        assert_eq!(
            sliced(MAP, &["src/B.php", "src/A.php"]),
            r#"{"format":1,"tests":[{"id":"a","seconds":0.5},{"id":"b"}],"files":{"src/A.php":{"3":[0,1],"4":[]},"src/B.php":{"1":[1]}},"methods":{"src/B.php":[{"name":"m","start":1,"end":2}]}}"#
        );
        assert_eq!(
            sliced(MAP, &["src/A.php"]),
            r#"{"format":1,"tests":[{"id":"a","seconds":0.5},{"id":"b"}],"files":{"src/A.php":{"3":[0,1],"4":[]}}}"#
        );
    }

    #[test]
    fn keeps_what_every_process_loaded_after_the_methods_and_writes_none_for_none() {
        let map = r#"{"format":1,"tests":[{"id":"a"}],"files":{"src/A.php":{"1":[0]}},"methods":{"src/A.php":[{"name":"m","start":1,"end":1}]},"loads":[[[0,["p/q"],true]],[]],"keys":{}}"#;

        assert_eq!(
            sliced(map, &["src/A.php"]),
            r#"{"format":1,"tests":[{"id":"a"}],"files":{"src/A.php":{"1":[0]}},"methods":{"src/A.php":[{"name":"m","start":1,"end":1}]},"loads":[[[0,["p/q"],true]],[]]}"#
        );
        assert_eq!(
            sliced(map, &[]),
            r#"{"format":1,"tests":[{"id":"a"}],"files":{},"loads":[[[0,["p/q"],true]],[]]}"#
        );
        assert_eq!(
            sliced(r#"{"format":1,"tests":[],"files":{},"loads":[]}"#, &[]),
            r#"{"format":1,"tests":[],"files":{}}"#
        );
    }

    #[test]
    fn writes_no_files_as_an_empty_object() {
        assert_eq!(
            sliced(MAP, &[]),
            r#"{"format":1,"tests":[{"id":"a","seconds":0.5},{"id":"b"}],"files":{}}"#
        );
    }

    #[test]
    fn writes_files_php_keys_from_nought_in_order_as_a_list() {
        let map = r#"{"format":1,"tests":[],"files":{"x":{"1":[]},"0":{"1":[]},"1":{"2":[]}},"methods":{"0":[{"name":"m","start":1,"end":1}]}}"#;

        assert_eq!(
            sliced(map, &["0", "1"]),
            r#"{"format":1,"tests":[],"files":[{"1":[]},{"2":[]}],"methods":[[{"name":"m","start":1,"end":1}]]}"#
        );
        assert_eq!(
            sliced(map, &["1"]),
            r#"{"format":1,"tests":[],"files":{"1":{"2":[]}}}"#
        );
    }

    #[test]
    fn slices_no_map_the_gate_did_not_write() {
        assert_eq!(
            slicer(br#"{"format":1, "tests":[],"files":{}}"#)
                .unwrap_err()
                .expected,
            "a compact map, as the gate writes one"
        );
        assert_eq!(
            slicer(br#"{"format":1,"tests":[],"files":{"./a":{}}}"#)
                .unwrap_err()
                .at,
            32
        );
        assert_eq!(
            slicer(br#"{"format":1,"tests":[],"files":{},"methods":{"a//b":[]}}"#)
                .unwrap_err()
                .expected,
            "a path as the gate writes it"
        );
        assert_eq!(
            slicer(br#"{"format":2,"tests":[],"files":{}}"#)
                .unwrap_err()
                .expected,
            "a coverage map of format 1"
        );
    }

    #[test]
    fn keys_an_array_with_a_number_only_as_php_does() {
        assert_eq!(php_index("0"), Some(0));
        assert_eq!(php_index("12"), Some(12));
        assert_eq!(php_index("-3"), Some(-3));
        assert_eq!(php_index("-0"), None);
        assert_eq!(php_index("012"), None);
        assert_eq!(php_index("1a"), None);
        assert_eq!(php_index(""), None);
        assert_eq!(php_index("-"), None);
        assert_eq!(php_index("9223372036854775808"), None);
    }
}
