//! The coverage map Outbreak makes from php-code-coverage's PHP report
//! (ADR-0119): the report read as data, never run, and the map built and
//! written as the gate's PHP builds and writes it from the same report, with
//! what the gate decides handed in: each file's path, what the run timed and
//! loaded beside the report, the tests leaving and the files each stays on,
//! and where the map was measured with its entries' keys. Every byte it reads
//! it is handed, and it writes none.

pub mod map;
pub mod report;
pub mod request;
pub mod serial;
pub mod text;

use outbreak_map::json::Refused;

use crate::map::Map;
use crate::request::Asked;

/// The outline of a report's map as JSON: each file as the report names it,
/// and each test, in the map's order, with the places of the files it ran a
/// line of.
///
/// # Errors
///
/// Refuses a report it does not read, or a request for another format.
pub fn outline<'a>(report: &'a [u8], asked: &'a Asked<'a>) -> Result<Vec<u8>, Refused> {
    let report = read(report, asked)?;

    Ok(Map::of(&report, asked, Ok)?.outline())
}

/// The text of a report's map, as the gate writes it, admitted where an
/// admission is asked for.
///
/// # Errors
///
/// Refuses a report it does not read, a request for another format, or one
/// that gives a file no path or two files one.
pub fn convert<'a>(report: &'a [u8], asked: &'a Asked<'a>) -> Result<Vec<u8>, Refused> {
    let report = read(report, asked)?;
    let map = Map::of(&report, asked, map::paths(asked))?;

    Ok(match &asked.leaving {
        Some(leaving) => map.admitted(leaving).written(asked),
        None => map.written(asked),
    })
}

fn read<'a>(report: &'a [u8], asked: &Asked<'_>) -> Result<report::Report<'a>, Refused> {
    if asked.format != report::FORMAT {
        return Err(Refused {
            at: 0,
            expected: "the serialization format this reads",
        });
    }

    report::read(report)
}

#[cfg(test)]
mod tests {
    use super::{convert, outline};
    use crate::report::tests::{Parts, a, i, s};
    use crate::request;

    /// Each report of the gate's corpus, its request as the gate's adapter writes it, and the map the gate writes.
    macro_rules! corpus {
        ($($name:literal),*) => {
            [$((
                $name,
                &include_bytes!(concat!("../../../../tests/Fixtures/CoverageReports/", $name, "/coverage.php"))[..],
                include_str!(concat!("../../../../tests/Fixtures/CoverageReports/", $name, "/request.json")),
                include_str!(concat!("../../../../tests/Fixtures/CoverageReports/", $name, "/pest.json")),
            )),*]
        };
    }

    macro_rules! admitted {
        ($($name:literal),*) => {
            [$((
                $name,
                &include_bytes!(concat!("../../../../tests/Fixtures/CoverageReports/", $name, "/coverage.php"))[..],
                include_str!(concat!("../../../../tests/Fixtures/CoverageReports/", $name, "/admitted-request.json")),
                include_str!(concat!("../../../../tests/Fixtures/CoverageReports/", $name, "/admitted.json")),
            )),*]
        };
    }

    #[test]
    fn writes_the_map_the_gate_writes_of_each_report_of_its_corpus() {
        let corpus = corpus!["empty", "phpunit-run", "sorted-flag", "timed", "unordered"];
        let admitted = admitted!["phpunit-run", "sorted-flag", "timed", "unordered"];

        for (name, report, asked, written) in corpus.into_iter().chain(admitted) {
            let asked = request::read(asked.trim_end().as_bytes()).unwrap();

            assert_eq!(
                String::from_utf8(convert(report, &asked).unwrap()).unwrap(),
                written,
                "{name}"
            );
        }
    }

    #[test]
    fn writes_where_the_map_was_measured_and_its_keys_as_the_gate_writes_them() {
        let report = Parts::valid().text();
        let asked = br#"{"report":"r","format":4,"paths":{"/project/src/Ann.php":"src/Ann.php","/project/src/Zed.php":"src/Zed.php"},"timed":[["T\\D::d",1.5]],"measured":{"commit":"abc","dirty":false},"keys":{"tests/ATest.php":"k"}}"#;
        let asked = request::read(asked).unwrap();

        assert_eq!(
            String::from_utf8(convert(&report, &asked).unwrap()).unwrap(),
            r#"{"format":1,"commit":"abc","dirty":false,"tests":[{"id":"T\\A::a","seconds":0.25},{"id":"T\\B::b"},{"id":"T\\C::c","seconds":2.0},{"id":"T\\D::d","seconds":1.5}],"files":{"src/Ann.php":{"2":[0],"1":[]},"src/Zed.php":{"3":[1,0]}},"keys":{"tests/ATest.php":"k"}}"#
        );
    }

    #[test]
    fn outlines_the_map_each_file_as_the_report_names_it() {
        let report = Parts::valid().text();
        let asked = request::read(
            br#"{"report":"r","format":4,"timed":[["T\\D::d",1.5],["T\\A::a",9.0]]}"#,
        )
        .unwrap();

        assert_eq!(
            String::from_utf8(outline(&report, &asked).unwrap()).unwrap(),
            r#"{"files":["/project/src/Ann.php","/project/src/Zed.php"],"tests":[["T\\A::a",[0,1]],["T\\B::b",[1]],["T\\C::c",[]],["T\\D::d",[]]]}"#
        );
    }

    #[test]
    fn refuses_another_format_and_a_file_given_no_path_or_one_another_is_at() {
        let report = Parts::valid().text();
        let refused = |asked: &[u8]| {
            let asked = request::read(asked).unwrap();
            convert(&report, &asked).unwrap_err().expected
        };

        assert_eq!(
            outline(
                &report,
                &request::read(br#"{"report":"r","format":3}"#).unwrap()
            )
            .unwrap_err()
            .expected,
            "the serialization format this reads"
        );
        assert_eq!(
            refused(br#"{"report":"r","format":4,"paths":{"/project/src/Ann.php":"src/A.php"}}"#),
            "a path for each file"
        );
        assert_eq!(
            refused(br#"{"report":"r","format":4,"paths":{"/project/src/Ann.php":"src/A.php","/project/src/Zed.php":"src/A.php"}}"#),
            "a path no other file is at, PHP keeps as text"
        );
        assert_eq!(
            refused(br#"{"report":"r","format":4,"paths":{"/project/src/Ann.php":"12","/project/src/Zed.php":"src/Z.php"}}"#),
            "a path no other file is at, PHP keeps as text"
        );
    }

    #[test]
    fn admits_a_map_with_its_loads_as_the_gate_does() {
        let mut parts = Parts::valid();
        parts.lines = a(&[
            (
                s("A.php"),
                a(&[
                    (i(1), a(&[(i(0), i(1)), (i(7), i(1))])),
                    (i(2), a(&[(i(0), i(1))])),
                    (i(3), a(&[])),
                ]),
            ),
            (s("B.php"), a(&[(i(4), a(&[(i(0), i(1))]))])),
        ]);
        let report = parts.text();
        let asked = br#"{"report":"r","format":4,"paths":{"/project/src/A.php":"src/A.php","/project/src/B.php":"src/B.php"},"loads":[[["T\\A::a",["p"],true],["T\\Gone::g",[],false]],[]],"leaving":{"T\\A::a":["src/B.php"],"T\\C::c":[]}}"#;
        let asked = request::read(asked).unwrap();

        assert_eq!(
            String::from_utf8(convert(&report, &asked).unwrap()).unwrap(),
            r#"{"format":1,"tests":[{"id":"T\\B::b"},{"id":"T\\A::a","seconds":0.25}],"files":{"src/A.php":{"1":[0],"2":[],"3":[]},"src/B.php":{"4":[1]}},"loads":[[[1,["p"],true]],[]]}"#
        );
    }
}
