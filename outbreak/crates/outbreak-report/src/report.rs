//! php-code-coverage's PHP report, `coverage.php`, read as data: the PHP its
//! serializer writes, `return \unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'`,
//! in serialization format 4, holding `buildInformation`, `basePath`, a
//! `ProcessedCodeCoverageData` and `testResults`. Nothing in it is run.
//!
//! It reads only a report php-code-coverage's reader would read, and reads
//! it as that reader and the gate read it; it refuses any report it is not
//! sure of: any other shape, a property or key PHP would read otherwise, and
//! anything the gate would fail on.

use std::collections::{HashMap, HashSet};

use outbreak_map::json::Refused;

use crate::serial::{Cursor, Key};

/// The serialization format this reads.
pub const FORMAT: u64 = 4;

/// How the report opens: its format, and the start of the text it returns.
const OPENING: &[u8] = b"<?php // phpunit/php-code-coverage serialization format 4\nreturn \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n";

/// How the report ends.
const CLOSING: &[u8] = b"\nEND_OF_COVERAGE_SERIALIZATION\n);";

/// What ends the text the report returns, which the text itself must not hold.
const LABEL: &[u8] = b"END_OF_COVERAGE_SERIALIZATION";

/// The class of the report's coverage data.
const CLASS: &[u8] = b"SebastianBergmann\\CodeCoverage\\Data\\ProcessedCodeCoverageData";

/// The coverage data's properties, each private to its class.
const PROPERTIES: [&str; 8] = [
    "lineCoverage",
    "testIdToIndex",
    "nextTestIndex",
    "functionCoverage",
    "filesSeededFromStaticAnalysis",
    "collectsHitCounts",
    "lineCoverageSorted",
    "functionCoverageSorted",
];

/// A report, as the gate reads it.
#[derive(Debug, Clone, PartialEq)]
pub struct Report<'a> {
    /// The path the files' names are under.
    pub base: &'a str,
    /// Each test's id, in the order the report's list of tests names them.
    pub ids: Vec<&'a str>,
    /// Each file with an executable line, in the order the coverage data lists them.
    pub files: Vec<File<'a>>,
    /// Each test's seconds, as the report's results time them, in their order.
    pub times: Times<'a>,
}

/// A file of a report, and its executable lines in the report's order.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct File<'a> {
    /// Its name under the base path.
    pub name: &'a str,
    /// Each executable line.
    pub lines: Vec<Line>,
}

/// An executable line, and the tests that ran it by their place in [`Report::ids`], in that order.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Line {
    /// Its number.
    pub number: i64,
    /// The tests that ran it; none for a line no test ran.
    pub tests: Vec<u32>,
}

/// A file's lines as the coverage data serializes them: each line's number,
/// and the indices of the tests that ran it, none where it is not executable.
type Lines = Vec<(i64, Option<Vec<i64>>)>;

/// The coverage data as it is serialized, before its tests are found.
#[derive(Debug, Default)]
struct Data<'a> {
    files: Vec<(&'a [u8], Lines)>,
    ids: Vec<(&'a [u8], i64)>,
    sorted: bool,
}

/// The report a file holds.
///
/// # Errors
///
/// Refuses any text that is not a whole report this reads.
pub fn read(text: &[u8]) -> Result<Report<'_>, Refused> {
    let body = body(text)?;
    let start = OPENING.len();
    let mut cursor = Cursor::over(body);
    let refused = |refused: Refused| Refused {
        at: refused.at + start,
        expected: refused.expected,
    };
    let (base, data, times) = top(&mut cursor).map_err(refused)?;
    cursor.end().map_err(refused)?;

    found(base, data, times).map_err(refused)
}

/// The serialized text the report returns.
fn body(text: &[u8]) -> Result<&[u8], Refused> {
    let ended = text.len()
        - text
            .iter()
            .rev()
            .take_while(|byte| matches!(byte, b' ' | b'\t' | b'\n' | b'\r'))
            .count();
    let whole = text.get(..ended).unwrap_or_default();

    if !whole.starts_with(OPENING) {
        return Err(Refused {
            at: 0,
            expected: "php-code-coverage's report in serialization format 4",
        });
    }

    let body = whole
        .strip_suffix(CLOSING)
        .and_then(|rest| rest.get(OPENING.len()..))
        .ok_or(Refused {
            at: ended,
            expected: "the end php-code-coverage writes",
        })?;

    match body.windows(LABEL.len()).position(|window| window == LABEL) {
        Some(at) => Err(Refused {
            at: OPENING.len() + at,
            expected: "no end before the end",
        }),
        None => Ok(body),
    }
}

/// Each test's seconds, by its id, in the results' order.
type Times<'a> = Vec<(&'a str, f64)>;

/// The report's base path, coverage data and results.
fn top<'a>(cursor: &mut Cursor<'a>) -> Result<(&'a str, Data<'a>, Times<'a>), Refused> {
    let at = cursor.at();
    let count = cursor.array()?;
    let mut once = Once::default();
    let (mut base, mut data, mut times) = (None, None, None);

    for _ in 0..count {
        match once.text(cursor)? {
            b"buildInformation" => build(cursor)?,
            b"basePath" => base = Some(utf8(cursor.string()?, cursor.at())?),
            b"codeCoverage" => data = Some(coverage(cursor)?),
            b"testResults" => times = Some(results(cursor)?),
            _ => return Err(once.unknown()),
        }
    }

    cursor.close()?;

    match (once.has(b"buildInformation"), base, data, times) {
        (true, Some(base), Some(data), Some(times)) => Ok((base, data, times)),
        _ => Err(Refused {
            at,
            expected: "build information, a base path, coverage data and results",
        }),
    }
}

/// The build information, held to what php-code-coverage's reader asks of it.
fn build(cursor: &mut Cursor<'_>) -> Result<(), Refused> {
    let at = cursor.at();
    let information = Value::read(cursor)?;
    let valid = information.text(b"timestamp").is_some()
        && information
            .field(b"runtime")
            .is_some_and(|runtime| runtime.texts(&[b"name", b"version", b"vendorUrl"]))
        && information
            .field(b"phpCodeCoverage")
            .is_some_and(|coverage| {
                coverage.text(b"version").is_some()
                    && matches!(coverage.field(b"serializationFormat"), Some(Value::Integer))
                    && coverage.field(b"driverInformation").is_some_and(|driver| {
                        [&b"name"[..], b"version"]
                            .iter()
                            .all(|key| driver.text(key).is_some_and(|text| !text.is_empty()))
                    })
            })
        && information.field(b"git").is_none_or(|git| {
            git.texts(&[b"originUrl", b"branch", b"commit", b"status"])
                && matches!(git.field(b"isClean"), Some(Value::Boolean))
        });

    if valid {
        Ok(())
    } else {
        Err(Refused {
            at,
            expected: "the build information php-code-coverage writes",
        })
    }
}

/// The coverage data: an object of its class, each of its properties once.
fn coverage<'a>(cursor: &mut Cursor<'a>) -> Result<Data<'a>, Refused> {
    let at = cursor.at();
    let (class, count) = cursor.object()?;

    if class != CLASS {
        return Err(Refused {
            at,
            expected: "php-code-coverage's coverage data",
        });
    }

    let mut once = Once::default();
    let mut data = Data::default();

    for _ in 0..count {
        let name = once.text(cursor)?;
        let property = name
            .strip_prefix(b"\0")
            .and_then(|name| name.strip_prefix(CLASS))
            .and_then(|name| name.strip_prefix(b"\0"))
            .ok_or_else(|| once.unknown())?;

        match property {
            b"lineCoverage" => data.files = lines(cursor)?,
            b"testIdToIndex" => data.ids = indices(cursor)?,
            b"nextTestIndex" => drop(cursor.integer()?),
            b"functionCoverage" => none(cursor)?,
            b"filesSeededFromStaticAnalysis" => {
                skip_array(cursor)?;
            }
            b"collectsHitCounts" | b"functionCoverageSorted" => drop(cursor.boolean()?),
            b"lineCoverageSorted" => data.sorted = cursor.boolean()?,
            _ => return Err(once.unknown()),
        }
    }

    cursor.close()?;

    if once.count() == PROPERTIES.len() {
        Ok(data)
    } else {
        Err(Refused {
            at,
            expected: "every property of php-code-coverage's coverage data",
        })
    }
}

/// Each file's lines, each executable one with the indices of the tests that ran it.
fn lines<'a>(cursor: &mut Cursor<'a>) -> Result<Vec<(&'a [u8], Lines)>, Refused> {
    let count = cursor.array()?;
    let mut files = Vec::with_capacity(count.min(1 << 16));
    let mut names = Once::default();

    for _ in 0..count {
        let name = names.text(cursor)?;
        let lines = cursor.array()?;
        let mut numbers = HashSet::new();
        let mut read = Vec::with_capacity(lines.min(1 << 16));

        for _ in 0..lines {
            let at = cursor.at();
            let number = match cursor.key()? {
                Key::Integer(number) if number > 0 && numbers.insert(number) => number,
                _ => {
                    return Err(Refused {
                        at,
                        expected: "a line's number, once",
                    });
                }
            };
            read.push((number, hits(cursor)?));
        }

        cursor.close()?;
        files.push((name, read));
    }

    cursor.close()?;

    Ok(files)
}

/// The indices of the tests that ran a line; none where it is not executable.
fn hits(cursor: &mut Cursor<'_>) -> Result<Option<Vec<i64>>, Refused> {
    if cursor.kind() == Some(b'N') {
        cursor.null()?;
        return Ok(None);
    }

    let count = cursor.array()?;
    let mut indices = Vec::with_capacity(count.min(1 << 16));

    for _ in 0..count {
        let at = cursor.at();

        match cursor.key()? {
            Key::Integer(index) => indices.push(index),
            Key::Text(_) => {
                return Err(Refused {
                    at,
                    expected: "a test's index",
                });
            }
        }

        cursor.integer()?;
    }

    cursor.close()?;

    Ok(Some(indices))
}

/// Each test's id and index, in the list's order.
fn indices<'a>(cursor: &mut Cursor<'a>) -> Result<Vec<(&'a [u8], i64)>, Refused> {
    let count = cursor.array()?;
    let mut ids = Vec::with_capacity(count.min(1 << 16));
    let mut once = Once::default();

    for _ in 0..count {
        let id = once.text(cursor)?;
        ids.push((id, cursor.integer()?));
    }

    cursor.close()?;

    Ok(ids)
}

/// Each test's seconds, as its result times it, in their order.
fn results<'a>(cursor: &mut Cursor<'a>) -> Result<Times<'a>, Refused> {
    let count = cursor.array()?;
    let mut times = Vec::with_capacity(count.min(1 << 16));
    let mut once = Once::default();

    for _ in 0..count {
        let id = once.text(cursor)?;
        let id = utf8(id, cursor.at())?;
        let at = cursor.at();
        let fields = cursor.array()?;
        let mut keys = Once::default();
        let mut time = None;

        for _ in 0..fields {
            if keys.key(cursor)? == Key::Text(b"time") {
                time = Some(cursor.number()?);
            } else {
                cursor.skip()?;
            }
        }

        cursor.close()?;
        times.push((
            id,
            time.ok_or(Refused {
                at,
                expected: "a result's time",
            })?,
        ));
    }

    cursor.close()?;

    Ok(times)
}

/// `a:0:{}`.
fn none(cursor: &mut Cursor<'_>) -> Result<(), Refused> {
    let at = cursor.at();

    if cursor.array()? == 0 {
        cursor.close()
    } else {
        Err(Refused {
            at,
            expected: "no function coverage",
        })
    }
}

/// An array, read for its form alone.
fn skip_array(cursor: &mut Cursor<'_>) -> Result<(), Refused> {
    if cursor.kind() == Some(b'a') {
        cursor.skip()
    } else {
        Err(Refused {
            at: cursor.at(),
            expected: "an array",
        })
    }
}

/// The report as the gate reads it: its tests found, each line's tests in
/// the list's order, and its files in the order the coverage data lists
/// them, sorted by name where it says it is not.
fn found<'a>(base: &'a str, data: Data<'a>, times: Times<'a>) -> Result<Report<'a>, Refused> {
    let mut ids = Vec::with_capacity(data.ids.len());
    let mut ranks = HashMap::with_capacity(data.ids.len());

    for (id, index) in data.ids {
        let rank = u32::try_from(ids.len()).unwrap_or(u32::MAX);
        ids.push(utf8(id, 0)?);

        if ranks.insert(index, rank).is_some() {
            return Err(refused("each test's index once"));
        }
    }

    let mut files = Vec::with_capacity(data.files.len());

    for (name, lines) in data.files {
        let name = utf8(name, 0)?;

        if !data.sorted && looks_numeric(name) {
            return Err(refused("a file's name PHP sorts as text"));
        }

        let lines: Vec<Line> = lines
            .into_iter()
            .filter_map(|(number, hits)| {
                hits.map(|hits| Line {
                    number,
                    tests: ranked(&hits, &ranks),
                })
            })
            .collect();

        if !lines.is_empty() {
            files.push(File { name, lines });
        }
    }

    if !data.sorted {
        files.sort_by(|left, right| left.name.as_bytes().cmp(right.name.as_bytes()));
    }

    Ok(Report {
        base,
        ids,
        files,
        times,
    })
}

/// The places in the list of the tests whose indices a line's hits name, in
/// the list's order, each once; an index the list does not name is left out.
fn ranked(hits: &[i64], ranks: &HashMap<i64, u32>) -> Vec<u32> {
    let mut tests: Vec<u32> = hits
        .iter()
        .filter_map(|index| ranks.get(index).copied())
        .collect();
    tests.sort_unstable();
    tests.dedup();

    tests
}

fn utf8(bytes: &[u8], at: usize) -> Result<&str, Refused> {
    std::str::from_utf8(bytes).map_err(|_| Refused {
        at,
        expected: "UTF-8",
    })
}

/// Whether PHP turns a key of this text into an integer: `0`, or digits with
/// no leading nought, after an optional minus.
#[must_use]
pub fn is_whole_number(text: &str) -> bool {
    let digits = text.strip_prefix('-').unwrap_or(text);

    !digits.is_empty()
        && digits.bytes().all(|byte| byte.is_ascii_digit())
        && (digits == "0" && text == "0" || !digits.starts_with('0'))
}

/// Whether PHP might compare a name as a number when it sorts names: one
/// made only of what a number is written with.
fn looks_numeric(name: &str) -> bool {
    !name.is_empty()
        && name.bytes().all(|byte| {
            matches!(
                byte,
                b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c | b'0'
                    ..=b'9' | b'.' | b'e' | b'E' | b'+' | b'-'
            )
        })
}

fn refused(expected: &'static str) -> Refused {
    Refused { at: 0, expected }
}

/// The string keys of one array or object read so far, each once.
#[derive(Debug, Default)]
struct Once<'a> {
    seen: HashSet<&'a [u8]>,
    last: usize,
}

impl<'a> Once<'a> {
    /// The next key, a string not read before.
    fn text(&mut self, cursor: &mut Cursor<'a>) -> Result<&'a [u8], Refused> {
        match self.key(cursor)? {
            Key::Text(text) => Ok(text),
            Key::Integer(_) => Err(self.unknown()),
        }
    }

    /// The next key, a string key not read before, or an integer.
    fn key(&mut self, cursor: &mut Cursor<'a>) -> Result<Key<'a>, Refused> {
        self.last = cursor.at();
        let key = cursor.key()?;

        match key {
            Key::Text(text) if !self.seen.insert(text) || is_whole_bytes(text) => Err(Refused {
                at: self.last,
                expected: "each key once, as text",
            }),
            other => Ok(other),
        }
    }

    fn has(&self, key: &[u8]) -> bool {
        self.seen.contains(key)
    }

    fn count(&self) -> usize {
        self.seen.len()
    }

    fn unknown(&self) -> Refused {
        Refused {
            at: self.last,
            expected: "a key php-code-coverage writes",
        }
    }
}

fn is_whole_bytes(text: &[u8]) -> bool {
    std::str::from_utf8(text).is_ok_and(is_whole_number)
}

/// A small value of the build information, read whole.
#[derive(Debug, Clone, PartialEq)]
enum Value<'a> {
    Null,
    Boolean,
    Integer,
    Float,
    Text(&'a [u8]),
    Array(Vec<(Key<'a>, Value<'a>)>),
}

impl<'a> Value<'a> {
    fn read(cursor: &mut Cursor<'a>) -> Result<Self, Refused> {
        Self::within(cursor, crate::serial::DEEPEST)
    }

    fn within(cursor: &mut Cursor<'a>, depth: usize) -> Result<Self, Refused> {
        let deeper = depth.checked_sub(1).ok_or(Refused {
            at: cursor.at(),
            expected: "a value nested less deeply",
        })?;

        Ok(match cursor.kind() {
            Some(b'N') => cursor.null().map(|()| Self::Null)?,
            Some(b'b') => cursor.boolean().map(|_| Self::Boolean)?,
            Some(b'i') => cursor.integer().map(|_| Self::Integer)?,
            Some(b'd') => cursor.float().map(|_| Self::Float)?,
            Some(b's') => Self::Text(cursor.string()?),
            _ => {
                let count = cursor.array()?;
                let mut once = Once::default();
                let mut entries = Vec::with_capacity(count.min(64));

                for _ in 0..count {
                    let key = once.key(cursor)?;
                    entries.push((key, Self::within(cursor, deeper)?));
                }

                cursor.close()?;
                Self::Array(entries)
            }
        })
    }

    fn field(&self, name: &[u8]) -> Option<&Self> {
        match self {
            Self::Array(entries) => entries
                .iter()
                .find(|(key, _)| *key == Key::Text(name))
                .map(|(_, value)| value),
            _ => None,
        }
    }

    fn text(&self, name: &[u8]) -> Option<&'a [u8]> {
        match self.field(name) {
            Some(Self::Text(text)) => Some(text),
            _ => None,
        }
    }

    fn texts(&self, names: &[&[u8]]) -> bool {
        names.iter().all(|name| self.text(name).is_some())
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use super::{CLASS, FORMAT, File, Line, read};

    pub fn s(text: &str) -> String {
        format!("s:{}:\"{text}\";", text.len())
    }

    pub fn i(number: i64) -> String {
        format!("i:{number};")
    }

    pub fn a(entries: &[(String, String)]) -> String {
        let inner = entries
            .iter()
            .map(|(key, value)| [key.as_str(), value.as_str()].concat())
            .collect::<String>();

        format!("a:{}:{{{inner}}}", entries.len())
    }

    fn class() -> &'static str {
        std::str::from_utf8(CLASS).unwrap()
    }

    /// A report's parts, each valid until a test changes it.
    pub struct Parts {
        pub build: String,
        pub base: String,
        pub lines: String,
        pub ids: String,
        pub functions: String,
        pub seeded: String,
        pub sorted: String,
        pub results: String,
        pub top: Vec<(String, String)>,
        pub properties: Vec<(String, String)>,
    }

    impl Parts {
        pub fn valid() -> Self {
            Self {
                build: a(&[
                    (s("timestamp"), s("Sun Nov 8")),
                    (
                        s("runtime"),
                        a(&[
                            (s("name"), s("PHP")),
                            (s("version"), s("8.5.7")),
                            (s("vendorUrl"), s("u")),
                        ]),
                    ),
                    (
                        s("phpCodeCoverage"),
                        a(&[
                            (s("version"), s("14.5.0")),
                            (s("serializationFormat"), i(4)),
                            (
                                s("driverInformation"),
                                a(&[(s("name"), s("PCOV")), (s("version"), s("1.0.12"))]),
                            ),
                        ]),
                    ),
                ]),
                base: s("/project/src/"),
                lines: a(&[
                    (
                        s("Zed.php"),
                        a(&[
                            (i(3), a(&[(i(7), i(1)), (i(0), i(2)), (i(9), i(1))])),
                            (i(4), "N;".into()),
                        ]),
                    ),
                    (
                        s("Ann.php"),
                        a(&[(i(1), a(&[])), (i(2), a(&[(i(0), i(1))]))]),
                    ),
                    (s("Null.php"), a(&[(i(1), "N;".into())])),
                ]),
                ids: a(&[(s("T\\B::b"), i(7)), (s("T\\A::a"), i(0))]),
                functions: a(&[]),
                seeded: a(&[(s("Null.php"), "b:1;".into())]),
                sorted: "b:0;".into(),
                results: a(&[
                    (
                        s("T\\A::a"),
                        a(&[
                            (s("size"), s("small")),
                            (s("time"), "d:0.25;".into()),
                            (i(0), "N;".into()),
                        ]),
                    ),
                    (s("T\\C::c"), a(&[(s("time"), i(2))])),
                ]),
                top: Vec::new(),
                properties: Vec::new(),
            }
        }

        pub fn body(&self) -> String {
            let property = |name: &str| s(&format!("\0{}\0{name}", class()));
            let mut properties = vec![
                (property("lineCoverage"), self.lines.clone()),
                (property("testIdToIndex"), self.ids.clone()),
                (property("nextTestIndex"), i(8)),
                (property("functionCoverage"), self.functions.clone()),
                (
                    property("filesSeededFromStaticAnalysis"),
                    self.seeded.clone(),
                ),
                (property("collectsHitCounts"), "b:0;".into()),
                (property("lineCoverageSorted"), self.sorted.clone()),
                (property("functionCoverageSorted"), "b:1;".into()),
            ];
            properties.extend(self.properties.iter().cloned());
            let inner = properties
                .iter()
                .map(|(key, value)| [key.as_str(), value.as_str()].concat())
                .collect::<String>();
            let data = format!(
                "O:{}:\"{}\":{}:{{{inner}}}",
                CLASS.len(),
                class(),
                properties.len()
            );
            let mut top = vec![
                (s("buildInformation"), self.build.clone()),
                (s("basePath"), self.base.clone()),
                (s("codeCoverage"), data),
                (s("testResults"), self.results.clone()),
            ];
            top.extend(self.top.iter().cloned());

            a(&top)
        }

        pub fn text(&self) -> Vec<u8> {
            wrapped(&self.body())
        }
    }

    pub fn wrapped(body: &str) -> Vec<u8> {
        format!(
            "<?php // phpunit/php-code-coverage serialization format 4\nreturn \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n{body}\nEND_OF_COVERAGE_SERIALIZATION\n);"
        )
        .into_bytes()
    }

    fn refused(text: &[u8]) -> &'static str {
        read(text).unwrap_err().expected
    }

    #[test]
    fn reads_a_report_as_the_gate_reads_it() {
        let text = Parts::valid().text();
        let report = read(&text).unwrap();

        assert_eq!(FORMAT, 4);
        assert_eq!(report.base, "/project/src/");
        assert_eq!(report.ids, ["T\\B::b", "T\\A::a"]);
        assert_eq!(
            report.files,
            [
                File {
                    name: "Ann.php",
                    lines: vec![
                        Line {
                            number: 1,
                            tests: vec![]
                        },
                        Line {
                            number: 2,
                            tests: vec![1]
                        }
                    ],
                },
                File {
                    name: "Zed.php",
                    lines: vec![Line {
                        number: 3,
                        tests: vec![0, 1]
                    }],
                },
            ]
        );
        assert_eq!(report.times, [("T\\A::a", 0.25), ("T\\C::c", 2.0)]);
    }

    #[test]
    fn keeps_the_files_in_their_order_where_the_report_says_they_are_sorted() {
        let mut parts = Parts::valid();
        parts.sorted = "b:1;".into();
        parts.lines = a(&[
            (s("1e3"), a(&[(i(1), a(&[]))])),
            (s("1.5"), a(&[(i(1), a(&[]))])),
        ]);
        let text = parts.text();
        let names: Vec<&str> = read(&text)
            .unwrap()
            .files
            .iter()
            .map(|file| file.name)
            .collect();

        assert_eq!(names, ["1e3", "1.5"]);

        parts.lines = a(&[
            (s("B.php"), a(&[(i(1), a(&[]))])),
            (s("A.php"), a(&[(i(1), a(&[]))])),
        ]);
        let text = parts.text();
        let names: Vec<&str> = read(&text)
            .unwrap()
            .files
            .iter()
            .map(|file| file.name)
            .collect();

        assert_eq!(names, ["B.php", "A.php"]);
    }

    #[test]
    fn reads_trailing_whitespace_after_the_report() {
        let mut text = Parts::valid().text();
        text.extend_from_slice(b" \n\t\r");

        assert!(read(&text).is_ok());
    }

    /// The text of a valid report with one change.
    fn changed(change: impl Fn(&mut Parts)) -> Vec<u8> {
        let mut parts = Parts::valid();
        change(&mut parts);

        parts.text()
    }

    /// That each text is refused as expected, each `@@` in it a byte that is not UTF-8.
    fn assert_refused(cases: &[(Vec<u8>, &str)]) {
        for (text, expected) in cases {
            let text = String::from_utf8_lossy(text).replace("@@", "\u{1}\u{1}");
            let text: Vec<u8> = text
                .bytes()
                .map(|byte| if byte == 1 { 0xff } else { byte })
                .collect();

            let shown = String::from_utf8_lossy(&text);

            assert_eq!(refused(&text), *expected, "{shown}");
        }
    }

    #[test]
    fn refuses_a_report_that_is_not_whole_as_php_code_coverage_writes_it() {
        let body = Parts::valid().body();
        let whole = wrapped(&body);
        let formatted = |format: u8| -> Vec<u8> {
            let mut text = whole.clone();
            text[56] = format;
            text
        };

        assert_eq!(whole[56], b'4');
        assert_refused(&[
            (b"<?php return [];".to_vec(), "php-code-coverage's report in serialization format 4"),
            (formatted(b'3'), "php-code-coverage's report in serialization format 4"),
            (whole[..whole.len() - 1].to_vec(), "the end php-code-coverage writes"),
            ([whole.clone(), b"\necho 1;".to_vec()].concat(), "the end php-code-coverage writes"),
            ([whole.clone(), b"\0".to_vec()].concat(), "the end php-code-coverage writes"),
            (wrapped(&format!("{body}N;")), "the end of the value"),
            (wrapped(&format!("{}{body}", s("END_OF_COVERAGE_SERIALIZATION"))), "no end before the end"),
            (
                b"<?php // phpunit/php-code-coverage serialization format 4\nreturn \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\nEND_OF_COVERAGE_SERIALIZATION\n);".to_vec(),
                "the end php-code-coverage writes",
            ),
            (
                wrapped(&a(&[(s("buildInformation"), Parts::valid().build)])),
                "build information, a base path, coverage data and results",
            ),
        ]);
    }

    #[test]
    fn refuses_a_report_whose_parts_it_is_not_sure_of() {
        let property = |name: &str| s(&format!("\0{}\0{name}", class()));
        let renamed = Parts::valid().body().replacen(
            class(),
            "SebastianBergmann\\CodeCoverage\\Data\\ProcessedCodeCoverageDatb",
            1,
        );
        let short = Parts::valid().body().replacen(":8:{", ":7:{", 1).replacen(
            &format!("{}b:1;}}", property("functionCoverageSorted")),
            "}",
            1,
        );

        assert_refused(&[
            (
                changed(|parts| parts.top.push((s("extra"), "N;".into()))),
                "a key php-code-coverage writes",
            ),
            (
                changed(|parts| parts.top.push((i(1), "N;".into()))),
                "a key php-code-coverage writes",
            ),
            (
                changed(|parts| parts.top.push((s("basePath"), s("again")))),
                "each key once, as text",
            ),
            (
                changed(|parts| parts.build = a(&[])),
                "the build information php-code-coverage writes",
            ),
            (
                changed(|parts| parts.build = "O:1:\"C\":0:{}".into()),
                "an array",
            ),
            (changed(|parts| parts.base = i(1)), "a string"),
            (changed(|parts| parts.base = s("@@")), "UTF-8"),
            (
                changed(|parts| parts.properties.push((s("extra"), "N;".into()))),
                "a key php-code-coverage writes",
            ),
            (
                changed(|parts| parts.properties.push((property("extra"), "N;".into()))),
                "a key php-code-coverage writes",
            ),
            (
                changed(|parts| parts.properties.push((property("lineCoverage"), a(&[])))),
                "each key once, as text",
            ),
            (
                changed(|parts| parts.functions = a(&[(s("f"), "N;".into())])),
                "no function coverage",
            ),
            (changed(|parts| parts.seeded = "N;".into()), "an array"),
            (changed(|parts| parts.sorted = i(1)), "a boolean"),
            (wrapped(&renamed), "php-code-coverage's coverage data"),
            (
                wrapped(&short),
                "every property of php-code-coverage's coverage data",
            ),
        ]);
    }

    #[test]
    fn refuses_lines_tests_and_results_it_is_not_sure_of() {
        let file = |lines: String| a(&[(s("A.php"), lines)]);

        assert_refused(&[
            (
                changed(|parts| parts.lines = file(a(&[(i(0), a(&[]))]))),
                "a line's number, once",
            ),
            (
                changed(|parts| parts.lines = file(a(&[(i(1), a(&[])), (i(1), a(&[]))]))),
                "a line's number, once",
            ),
            (
                changed(|parts| parts.lines = file(a(&[(s("x"), a(&[]))]))),
                "a line's number, once",
            ),
            (
                changed(|parts| parts.lines = file(a(&[(i(1), a(&[(s("x"), i(1))]))]))),
                "a test's index",
            ),
            (
                changed(|parts| parts.lines = file(a(&[(i(1), a(&[(i(0), s("x"))]))]))),
                "an integer",
            ),
            (
                changed(|parts| parts.lines = a(&[(s("A.php"), a(&[])), (s("A.php"), a(&[]))])),
                "each key once, as text",
            ),
            (
                changed(|parts| parts.lines = a(&[(s("12"), a(&[]))])),
                "each key once, as text",
            ),
            (
                changed(|parts| parts.lines = a(&[(s("1e3"), a(&[]))])),
                "a file's name PHP sorts as text",
            ),
            (
                changed(|parts| parts.lines = a(&[(s("@@"), a(&[]))])),
                "UTF-8",
            ),
            (
                changed(|parts| parts.ids = a(&[(s("T::a"), i(1)), (s("T::b"), i(1))])),
                "each test's index once",
            ),
            (
                changed(|parts| parts.ids = a(&[(s("T::a"), s("1"))])),
                "an integer",
            ),
            (
                changed(|parts| parts.ids = a(&[(s("T::a"), i(1)), (s("T::a"), i(2))])),
                "each key once, as text",
            ),
            (
                changed(|parts| parts.ids = a(&[(s("-7"), i(1))])),
                "each key once, as text",
            ),
            (changed(|parts| parts.ids = a(&[(s("@@"), i(1))])), "UTF-8"),
            (
                changed(|parts| parts.results = a(&[(s("T::a"), a(&[(s("size"), s("small"))]))])),
                "a result's time",
            ),
            (
                changed(|parts| parts.results = a(&[(s("T::a"), "N;".into())])),
                "an array",
            ),
            (
                changed(|parts| parts.results = a(&[(s("T::a"), a(&[(s("time"), s("1"))]))])),
                "a float",
            ),
            (
                changed(|parts| {
                    parts.results = a(&[(s("T::a"), a(&[(s("time"), i(1)), (s("time"), i(2))]))]);
                }),
                "each key once, as text",
            ),
        ]);
    }

    /// Build information of these entries, around a valid runtime and coverage where they are not given.
    fn build(entries: &[(String, String)]) -> Result<(), &'static str> {
        let mut parts = Parts::valid();
        parts.build = a(entries);

        read(&parts.text())
            .map(drop)
            .map_err(|refused| refused.expected)
    }

    fn runtime() -> (String, String) {
        (
            s("runtime"),
            a(&[
                (s("name"), s("PHP")),
                (s("version"), s("8.5.7")),
                (s("vendorUrl"), s("u")),
            ]),
        )
    }

    fn coverage(driver: &str, format: &str) -> (String, String) {
        (
            s("phpCodeCoverage"),
            a(&[
                (s("version"), s("14.5.0")),
                (s("serializationFormat"), format.into()),
                (
                    s("driverInformation"),
                    a(&[(s("name"), s(driver)), (s("version"), s("1"))]),
                ),
            ]),
        )
    }

    fn git(clean: &str) -> (String, String) {
        let fields = [
            ("originUrl", "o"),
            ("branch", "b"),
            ("commit", "c"),
            ("status", ""),
        ];
        let mut entries: Vec<(String, String)> = fields
            .iter()
            .map(|(key, value)| (s(key), s(value)))
            .collect();
        entries.push((s("isClean"), clean.into()));

        (s("git"), a(&entries))
    }

    #[test]
    fn reads_the_build_information_php_code_coverage_asks_for() {
        let timestamp = (s("timestamp"), s("t"));

        assert!(build(&[timestamp.clone(), runtime(), coverage("PCOV", "i:4;")]).is_ok());
        assert!(build(&[timestamp, runtime(), coverage("PCOV", "i:4;"), git("b:1;")]).is_ok());
    }

    #[test]
    fn refuses_build_information_php_code_coverage_refuses() {
        let timestamp = || (s("timestamp"), s("t"));
        let refused = "the build information php-code-coverage writes";
        let deep = format!(
            "{}N;{}",
            "a:1:{i:0;".repeat(super::super::serial::DEEPEST),
            "}".repeat(super::super::serial::DEEPEST)
        );

        for entries in [
            vec![
                timestamp(),
                runtime(),
                coverage("PCOV", "i:4;"),
                git("i:1;"),
            ],
            vec![
                timestamp(),
                runtime(),
                coverage("PCOV", "i:4;"),
                (s("git"), "N;".into()),
            ],
            vec![(s("timestamp"), i(1)), runtime(), coverage("PCOV", "i:4;")],
            vec![
                (s("timestamp"), "b:1;".into()),
                runtime(),
                coverage("PCOV", "i:4;"),
            ],
            vec![
                (s("timestamp"), "d:1.5;".into()),
                runtime(),
                coverage("PCOV", "i:4;"),
            ],
            vec![
                timestamp(),
                (s("runtime"), a(&[])),
                coverage("PCOV", "i:4;"),
            ],
            vec![timestamp(), runtime(), coverage("PCOV", "d:4.0;")],
            vec![timestamp(), runtime(), coverage("", "i:4;")],
            vec![timestamp(), runtime(), (s("phpCodeCoverage"), "N;".into())],
        ] {
            assert_eq!(build(&entries), Err(refused));
        }

        assert_eq!(
            build(&[
                timestamp(),
                runtime(),
                coverage("PCOV", "i:4;"),
                (s("deep"), deep)
            ]),
            Err("a value nested less deeply")
        );
    }

    #[test]
    fn tells_which_keys_php_turns_into_whole_numbers() {
        for whole in ["0", "7", "-7", "123", "9223372036854775807"] {
            assert!(super::is_whole_number(whole), "{whole}");
        }

        for text in ["", "-", "-0", "01", "+1", " 1", "1.0", "1e3", "a1", "00"] {
            assert!(!super::is_whole_number(text), "{text}");
        }
    }
}
