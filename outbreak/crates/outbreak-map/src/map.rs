//! A coverage map's text, format 1, read for its shape: whether every entry in
//! it is one the gate's careful reading keeps, so the gate may build the map
//! straight from its decoded text and get the map the careful reading gets.
//!
//! The gate's careful reading (`MapFileRead`) drops any entry that is not well
//! formed. A map is vouched for only where it holds no entry to drop and no
//! entry two readings could take apart: every test listed once, with an id and
//! a duration of nought or more where it has one, every line a whole number of
//! one or more naming listed tests by their place, every method named,
//! starting at line one or later and ending no sooner, and every test a process
//! loaded named by its place, with the packages it loaded and whether it
//! started processes. Anything else, a key no
//! map writes or one written twice among them, is not vouched for, and the gate
//! reads the map carefully, as it would without Outbreak.

use std::collections::HashSet;
use std::ops::Range;

use crate::json::{Cursor, Key, Number, Refused};

/// The only format of map this reads.
const FORMAT: u64 = 1;

/// Why a text is not vouched for: where, and what it should have held there.
pub type Unvouched = Refused;

/// One file's entry in a map: its path, and where its key and its value are written.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Entry {
    /// The path, as the map writes it.
    pub path: String,
    /// Where the key is written, quotes included.
    pub key: Range<usize>,
    /// Where the value is written.
    pub value: Range<usize>,
}

/// What a vouched map holds, by where it is written.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Shape {
    /// Where the list of tests is written.
    pub tests: Range<usize>,
    /// Each file's lines, in the order the map writes them.
    pub files: Vec<Entry>,
    /// Each file's executed methods, in the order the map writes them.
    pub methods: Vec<Entry>,
    /// Where what each process's tests loaded is written, where the map holds it.
    pub loads: Option<Range<usize>>,
    /// Whether whitespace stood between any two of its tokens.
    pub spaced: bool,
}

/// A map's text read for its shape, where it is vouched for.
///
/// # Errors
///
/// Says why a text is not vouched for: it is not JSON, not a map of format 1,
/// or holds an entry the careful reading drops or could read apart.
pub fn read(text: &[u8]) -> Result<Shape, Unvouched> {
    let mut json = Cursor::over(text)?;
    let mut members = json.object()?;
    let mut seen = Seen::default();
    let mut format = None;
    let mut tests = None;
    let mut files = None;
    let mut methods = Vec::new();
    let mut loads = None;
    let mut places = Places::default();

    while let Some(key) = members.next(&mut json)? {
        seen.once(&key, json.at())?;

        match key.text.as_str() {
            "format" => format = Some(json.number()?),
            "commit" | "dirty" | "keys" => json.skip()?,
            "tests" => tests = Some(read_tests(&mut json, &mut places)?),
            "files" => files = Some(read_files(&mut json, &mut places)?),
            "methods" => methods = read_methods(&mut json)?,
            "loads" => loads = Some(read_loads(&mut json, &mut places)?),
            _ => return Err(refused(key.raw.start, "a key a coverage map writes")),
        }
    }

    json.end()?;
    let start = json.at();

    if format != Some(Number::Whole(FORMAT)) {
        return Err(refused(0, "a coverage map of format 1"));
    }

    let tests = tests.ok_or_else(|| refused(start, "a list of tests"))?;
    let files = files.ok_or_else(|| refused(start, "the files' lines"))?;
    places.within(start)?;

    Ok(Shape {
        tests,
        files,
        methods,
        loads,
        spaced: json.spaced(),
    })
}

/// The path as the gate's `Path::of` writes it: each `\` read as `/`, every empty and `.`
/// segment left out, `.` for none, and a leading `/` kept.
#[must_use]
pub fn normalised(path: &str) -> String {
    let joined = path
        .replace('\\', "/")
        .split('/')
        .filter(|segment| !segment.is_empty() && *segment != ".")
        .collect::<Vec<_>>()
        .join("/");

    match (joined.is_empty(), path.starts_with('/')) {
        (true, _) => ".".to_owned(),
        (false, true) => format!("/{joined}"),
        (false, false) => joined,
    }
}

/// The keys of one object read so far, so a key written twice is refused.
#[derive(Debug, Default)]
struct Seen {
    keys: HashSet<String>,
}

impl Seen {
    fn once(&mut self, key: &Key, at: usize) -> Result<(), Unvouched> {
        if self.keys.insert(key.text.clone()) {
            Ok(())
        } else {
            Err(refused(at, "each key once"))
        }
    }
}

/// How many tests the map lists, and the largest place a line names.
#[derive(Debug, Default)]
struct Places {
    tests: u64,
    largest: Option<u64>,
}

impl Places {
    fn within(&self, at: usize) -> Result<(), Unvouched> {
        match self.largest {
            Some(place) if place >= self.tests => Err(refused(at, "places of listed tests")),
            _ => Ok(()),
        }
    }
}

fn read_tests(json: &mut Cursor<'_>, places: &mut Places) -> Result<Range<usize>, Unvouched> {
    let start = json.value_start();
    let mut items = json.array()?;
    let mut ids = HashSet::new();

    while items.next(json)? {
        let at = json.at();

        if !ids.insert(read_test(json)?) {
            return Err(refused(at, "each test once"));
        }

        places.tests += 1;
    }

    Ok(start..json.at())
}

fn read_test(json: &mut Cursor<'_>) -> Result<String, Unvouched> {
    let mut members = json.object()?;
    let mut seen = Seen::default();
    let mut id = None;

    while let Some(key) = members.next(json)? {
        seen.once(&key, json.at())?;

        match key.text.as_str() {
            "id" => id = Some(json.string()?),
            "seconds" => {
                if let Number::Other { negative: true } = json.number()? {
                    return Err(refused(key.raw.start, "seconds of nought or more"));
                }
            }
            _ => return Err(refused(key.raw.start, "a key a test writes")),
        }
    }

    id.ok_or_else(|| refused(json.at(), "a test's id"))
}

fn read_files(json: &mut Cursor<'_>, places: &mut Places) -> Result<Vec<Entry>, Unvouched> {
    entries(json, |json| {
        let mut lines = json.object()?;
        let mut seen = Seen::default();

        while let Some(line) = lines.next(json)? {
            seen.once(&line, json.at())?;

            if !is_line(&line.text) {
                return Err(refused(line.raw.start, "a line of one or more"));
            }

            places.largest = places.largest.max(json.wholes()?);
        }

        Ok(())
    })
}

fn read_methods(json: &mut Cursor<'_>) -> Result<Vec<Entry>, Unvouched> {
    entries(json, |json| {
        let mut items = json.array()?;

        while items.next(json)? {
            read_method(json)?;
        }

        Ok(())
    })
}

fn read_method(json: &mut Cursor<'_>) -> Result<(), Unvouched> {
    let at = json.at();
    let mut members = json.object()?;
    let mut seen = Seen::default();
    let (mut name, mut start, mut end) = (None, None, None);

    while let Some(key) = members.next(json)? {
        seen.once(&key, json.at())?;

        match key.text.as_str() {
            "name" => name = Some(json.string()?),
            "start" => start = Some(json.number()?),
            "end" => end = Some(json.number()?),
            _ => return Err(refused(key.raw.start, "a key a method writes")),
        }
    }

    match (name, start, end) {
        (Some(name), Some(Number::Whole(first)), Some(Number::Whole(last)))
            if !name.is_empty() && first >= 1 && last >= first && i64::try_from(last).is_ok() =>
        {
            Ok(())
        }
        _ => Err(refused(
            at,
            "a method named, from line one or later to no sooner",
        )),
    }
}

/// What each process's tests loaded: a list of processes, each a list of its tests.
fn read_loads(json: &mut Cursor<'_>, places: &mut Places) -> Result<Range<usize>, Unvouched> {
    let start = json.value_start();
    let mut processes = json.array()?;

    while processes.next(json)? {
        let mut tests = json.array()?;

        while tests.next(json)? {
            read_load(json, places)?;
        }
    }

    Ok(start..json.at())
}

/// One test a process loaded: its place among the listed tests, the packages it loaded, and
/// whether it started processes, those three alone.
fn read_load(json: &mut Cursor<'_>, places: &mut Places) -> Result<(), Unvouched> {
    let at = json.value_start();
    let load = || {
        refused(
            at,
            "a test's place, its packages and whether it started processes",
        )
    };
    let mut parts = json.array()?;

    if !parts.next(json)? {
        return Err(load());
    }

    let Number::Whole(place) = json.number()? else {
        return Err(load());
    };
    places.largest = places.largest.max(Some(place));

    if !parts.next(json)? {
        return Err(load());
    }

    let mut packages = json.array()?;

    while packages.next(json)? {
        json.string()?;
    }

    if !parts.next(json)? {
        return Err(load());
    }

    json.boolean()?;

    if parts.next(json)? {
        return Err(load());
    }

    Ok(())
}

/// Each entry of an object keyed by path, its value read by `each`.
fn entries(
    json: &mut Cursor<'_>,
    mut each: impl FnMut(&mut Cursor<'_>) -> Result<(), Unvouched>,
) -> Result<Vec<Entry>, Unvouched> {
    let mut members = json.object()?;
    let mut seen = Seen::default();
    let mut read = Vec::new();

    while let Some(key) = members.next(json)? {
        seen.once(&key, json.at())?;
        let start = json.value_start();
        each(json)?;
        read.push(Entry {
            path: key.text,
            key: key.raw,
            value: start..json.at(),
        });
    }

    Ok(read)
}

/// Whether a key names a line as the careful reading keeps one: digits alone, with no leading
/// nought, a whole number PHP holds.
fn is_line(key: &str) -> bool {
    key.bytes().next().is_some_and(|first| first != b'0')
        && key.bytes().all(|digit| digit.is_ascii_digit())
        && key.parse::<i64>().is_ok()
}

fn refused(at: usize, expected: &'static str) -> Unvouched {
    Refused { at, expected }
}

#[cfg(test)]
mod tests {
    use super::{Entry, Shape, normalised, read};
    use crate::json::Refused;

    fn refusal(text: &str) -> &'static str {
        read(text.as_bytes()).unwrap_err().expected
    }

    #[test]
    fn reads_the_shape_of_a_map_the_gate_writes() {
        let text = r#"{"format":1,"commit":"abc","dirty":false,"tests":[{"id":"a","seconds":0.5},{"id":"b"}],"files":{"src/A.php":{"3":[0,1],"4":[]}},"methods":{"src/A.php":[{"name":"m","start":3,"end":4}]},"keys":{"x":"y"}}"#;

        assert_eq!(
            read(text.as_bytes()).unwrap(),
            Shape {
                tests: 49..86,
                files: vec![Entry {
                    path: "src/A.php".into(),
                    key: 96..107,
                    value: 108..126,
                }],
                methods: vec![Entry {
                    path: "src/A.php".into(),
                    key: 139..150,
                    value: 151..183,
                }],
                loads: None,
                spaced: false,
            }
        );
        assert_eq!(&text[49..86], r#"[{"id":"a","seconds":0.5},{"id":"b"}]"#);
        assert_eq!(&text[96..107], r#""src/A.php""#);
        assert_eq!(&text[108..126], r#"{"3":[0,1],"4":[]}"#);
        assert_eq!(&text[151..183], r#"[{"name":"m","start":3,"end":4}]"#);
    }

    #[test]
    fn says_whitespace_stood_in_the_map() {
        assert!(
            read(br#"{ "format":1,"tests":[],"files":{}}"#)
                .unwrap()
                .spaced
        );
    }

    #[test]
    fn vouches_for_no_text_that_is_not_json() {
        assert_eq!(read(b"{\xff}").unwrap_err().expected, "UTF-8");
        assert_eq!(refusal("[]"), "an object");
        assert_eq!(
            refusal(r#"{"format":1,"tests":[],"files":{}} x"#),
            "the end of the text"
        );
    }

    #[test]
    fn vouches_for_no_map_of_another_format() {
        assert_eq!(
            refusal(r#"{"tests":[],"files":{}}"#),
            "a coverage map of format 1"
        );
        assert_eq!(
            refusal(r#"{"format":2,"tests":[],"files":{}}"#),
            "a coverage map of format 1"
        );
        assert_eq!(
            refusal(r#"{"format":1.0,"tests":[],"files":{}}"#),
            "a coverage map of format 1"
        );
    }

    #[test]
    fn vouches_for_no_map_without_its_tests_or_files() {
        assert_eq!(refusal(r#"{"format":1,"files":{}}"#), "a list of tests");
        assert_eq!(refusal(r#"{"format":1,"tests":[]}"#), "the files' lines");
    }

    #[test]
    fn vouches_for_no_key_a_map_does_not_write_or_writes_twice() {
        assert_eq!(
            refusal(r#"{"format":1,"tests":[],"files":{},"extra":1}"#),
            "a key a coverage map writes"
        );
        assert_eq!(
            refusal(r#"{"format":1,"format":1,"tests":[],"files":{}}"#),
            "each key once"
        );
    }

    #[test]
    fn vouches_for_no_test_the_careful_reading_drops_or_reads_twice() {
        let map = |tests: &str| format!(r#"{{"format":1,"tests":[{tests}],"files":{{}}}}"#);

        assert_eq!(refusal(&map(r#"{"seconds":1}"#)), "a test's id");
        assert_eq!(refusal(&map(r#"{"id":1}"#)), "text");
        assert_eq!(
            refusal(&map(r#"{"id":"a","seconds":-1}"#)),
            "seconds of nought or more"
        );
        assert_eq!(refusal(&map(r#"{"id":"a","seconds":"1"}"#)), "a number");
        assert_eq!(
            refusal(&map(r#"{"id":"a","name":"b"}"#)),
            "a key a test writes"
        );
        assert_eq!(refusal(&map(r#"{"id":"a","id":"b"}"#)), "each key once");
        assert_eq!(
            refusal(&map(r#"{"id":"a"},{"id":"\u0061"}"#)),
            "each test once"
        );
        assert_eq!(refusal(&map("[]")), "an object");
        read(map(r#"{"id":"a","seconds":1e400},{"id":"b","seconds":0}"#).as_bytes()).unwrap();
    }

    #[test]
    fn vouches_for_no_line_the_careful_reading_drops() {
        let map = |lines: &str| {
            format!(r#"{{"format":1,"tests":[{{"id":"a"}}],"files":{{"src/A.php":{lines}}}}}"#)
        };

        assert_eq!(refusal(&map(r#"{"0":[]}"#)), "a line of one or more");
        assert_eq!(refusal(&map(r#"{"01":[]}"#)), "a line of one or more");
        assert_eq!(refusal(&map(r#"{"1a":[]}"#)), "a line of one or more");
        assert_eq!(refusal(&map(r#"{"":[]}"#)), "a line of one or more");
        assert_eq!(
            refusal(&map(r#"{"9223372036854775808":[]}"#)),
            "a line of one or more"
        );
        assert_eq!(refusal(&map(r#"{"1":[1]}"#)), "places of listed tests");
        assert_eq!(refusal(&map(r#"{"1":[0.0]}"#)), "a whole number");
        assert_eq!(refusal(&map(r#"{"1":{}}"#)), "a list");
        assert_eq!(refusal(&map(r#"{"1":[],"1":[]}"#)), "each key once");
        assert_eq!(refusal(&map("[]")), "an object");
        read(map(r#"{"9223372036854775807":[0,0]}"#).as_bytes()).unwrap();
    }

    #[test]
    fn vouches_for_no_file_written_twice() {
        assert_eq!(
            refusal(r#"{"format":1,"tests":[],"files":{"a":{},"a":{}}}"#),
            "each key once"
        );
    }

    #[test]
    fn reads_where_what_each_process_loaded_is_written() {
        let text = r#"{"format":1,"tests":[{"id":"a"},{"id":"b"}],"files":{},"loads":[[[0,["p/q"],true],[1,[],false]],[]]}"#;

        assert_eq!(
            read(text.as_bytes())
                .unwrap()
                .loads
                .and_then(|loads| text.get(loads)),
            Some(r#"[[[0,["p/q"],true],[1,[],false]],[]]"#)
        );
    }

    #[test]
    fn vouches_for_no_load_the_careful_reading_drops_or_reads_otherwise() {
        let map = |loads: &str| {
            format!(r#"{{"format":1,"tests":[{{"id":"a"}}],"files":{{}},"loads":{loads}}}"#)
        };
        let whole = "a test's place, its packages and whether it started processes";

        read(map("[]").as_bytes()).unwrap();
        read(map(r#"[[[0,["p"],false]]]"#).as_bytes()).unwrap();
        assert_eq!(refusal(&map(r"[[[1,[],true]]]")), "places of listed tests");
        assert_eq!(refusal(&map(r"[[[-1,[],true]]]")), whole);
        assert_eq!(refusal(&map(r"[[[0.0,[],true]]]")), whole);
        assert_eq!(refusal(&map("[[[]]]")), whole);
        assert_eq!(refusal(&map("[[[0]]]")), whole);
        assert_eq!(refusal(&map("[[[0,[]]]]")), whole);
        assert_eq!(refusal(&map("[[[0,[],true,1]]]")), whole);
        assert_eq!(refusal(&map("[[[0,[1],true]]]")), "text");
        assert_eq!(refusal(&map("[[[0,[],1]]]")), "true or false");
        assert_eq!(refusal(&map("[[[0,{},true]]]")), "a list");
        assert_eq!(refusal(&map("[{}]")), "a list");
        assert_eq!(refusal(&map("{}")), "a list");
    }

    #[test]
    fn checks_places_against_tests_listed_after_the_lines() {
        read(br#"{"format":1,"files":{"a":{"1":[0]}},"tests":[{"id":"x"}]}"#).unwrap();
    }

    #[test]
    fn vouches_for_no_method_the_careful_reading_drops() {
        let map = |method: &str| {
            format!(r#"{{"format":1,"tests":[],"files":{{}},"methods":{{"a":[{method}]}}}}"#)
        };

        read(map(r#"{"name":"m","start":1,"end":1}"#).as_bytes()).unwrap();
        for method in [
            r#"{"name":"","start":1,"end":1}"#,
            r#"{"name":"m","start":0,"end":1}"#,
            r#"{"name":"m","start":2,"end":1}"#,
            r#"{"name":"m","start":1}"#,
            r#"{"start":1,"end":1}"#,
            r#"{"name":"m","start":1,"end":1.0}"#,
            r#"{"name":"m","start":1,"end":9223372036854775808}"#,
        ] {
            assert_eq!(
                refusal(&map(method)),
                "a method named, from line one or later to no sooner",
                "{method}"
            );
        }
        assert_eq!(
            refusal(&map(r#"{"name":"m","start":1,"end":1,"x":1}"#)),
            "a key a method writes"
        );
        assert_eq!(
            refusal(&map(r#"{"name":"m","name":"n","start":1,"end":1}"#)),
            "each key once"
        );
        assert_eq!(
            refusal(r#"{"format":1,"tests":[],"files":{},"methods":{"a":{}}}"#),
            "a list"
        );
    }

    #[test]
    fn reports_where_a_refusal_stands() {
        assert_eq!(
            read(br#"{"format":1,"tests":[],"files":{},"x":1}"#).unwrap_err(),
            Refused {
                at: 34,
                expected: "a key a coverage map writes"
            }
        );
    }

    #[test]
    fn writes_a_path_as_the_gate_does() {
        assert_eq!(normalised("src/A.php"), "src/A.php");
        assert_eq!(normalised("./src//A.php"), "src/A.php");
        assert_eq!(normalised("src\\A.php"), "src/A.php");
        assert_eq!(normalised("/abs/./x"), "/abs/x");
        assert_eq!(normalised(""), ".");
        assert_eq!(normalised("./"), ".");
        assert_eq!(normalised("a/../b"), "a/../b");
    }
}
