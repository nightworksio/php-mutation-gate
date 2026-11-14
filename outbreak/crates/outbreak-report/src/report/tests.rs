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
