//! `outbreak report outline` and `outbreak report convert`: php-code-coverage's
//! PHP report a request names, read as data, never run. The outline answers
//! each file of its map and the files each test ran a line of, so the gate
//! decides what of it the suites admit and its entries' keys; the conversion
//! writes the map the gate writes of it, gzipped, as the request asks. Either
//! declines a report it does not read, and the gate reads it itself.

use std::fs;

use outbreak_map::json::Refused;
use outbreak_report::request::{self, Asked};

use crate::split::packed;
use crate::{CONTRACT, Failure, said};

/// The outline of the report a request names, or why it is declined.
pub fn outline(input: &[u8]) -> Result<String, Failure> {
    let asked = asked(input)?;
    let report = report(&asked)?;

    Ok(match outbreak_report::outline(&report, &asked) {
        Ok(outline) => format!(
            r#"{{"outbreak":{CONTRACT},"outline":{}}}"#,
            String::from_utf8_lossy(&outline)
        ),
        Err(refused) => declined(&refused),
    })
}

/// The map of the report a request names, written where it asks, or why it is declined.
pub fn convert(input: &[u8]) -> Result<String, Failure> {
    let asked = asked(input)?;
    let into = asked
        .into
        .clone()
        .ok_or_else(|| Failure::Usage("the request names no map to write".to_owned()))?;
    let report = report(&asked)?;

    match outbreak_report::convert(&report, &asked) {
        Ok(map) => {
            packed(&into, &map)?;
            Ok(format!(r#"{{"outbreak":{CONTRACT},"written":1}}"#))
        }
        Err(refused) => Ok(declined(&refused)),
    }
}

fn asked(input: &[u8]) -> Result<Asked<'_>, Failure> {
    request::read(input).map_err(|refused| {
        Failure::Usage(format!("the report request is not one: {}", said(&refused)))
    })
}

fn report(asked: &Asked<'_>) -> Result<Vec<u8>, Failure> {
    fs::read(&asked.report)
        .map_err(|error| Failure::Io(format!("cannot read {}: {error}", asked.report)))
}

fn declined(refused: &Refused) -> String {
    format!(
        r#"{{"outbreak":{CONTRACT},"declined":"{}"}}"#,
        said(refused).replace('"', "'")
    )
}

#[cfg(test)]
mod tests {
    use std::fs;
    use std::io::Read;
    use std::path::PathBuf;

    use flate2::read::GzDecoder;

    use super::{convert, outline};
    use crate::Failure;

    const REPORT: &[u8] =
        include_bytes!("../../../../tests/Fixtures/CoverageReports/unordered/coverage.php");

    const MAP: &str =
        include_str!("../../../../tests/Fixtures/CoverageReports/unordered/pest.json");

    struct Scratch(PathBuf);

    impl Scratch {
        fn new(name: &str) -> Self {
            let path =
                std::env::temp_dir().join(format!("outbreak-report-{name}-{}", std::process::id()));
            drop(fs::remove_dir_all(&path));
            fs::create_dir_all(&path).unwrap();
            fs::write(path.join("coverage.php"), REPORT).unwrap();

            Self(path)
        }

        fn at(&self, name: &str) -> String {
            self.0.join(name).to_string_lossy().into_owned()
        }
    }

    impl Drop for Scratch {
        fn drop(&mut self) {
            drop(fs::remove_dir_all(&self.0));
        }
    }

    fn asked(scratch: &Scratch, into: &str) -> Vec<u8> {
        let asked =
            include_str!("../../../../tests/Fixtures/CoverageReports/unordered/request.json")
                .trim_end()
                .replace(
                    r#""report":"coverage.php""#,
                    &format!(r#""report":"{}""#, scratch.at("coverage.php")),
                )
                .replace(r#""into":"map.json.gz""#, &format!(r#""into":"{into}""#));

        asked.into_bytes()
    }

    #[test]
    fn writes_the_map_of_the_report_a_request_names() {
        let scratch = Scratch::new("writes");
        let into = scratch.at("coverage/map.json.gz");
        let mut text = String::new();

        assert_eq!(
            convert(&asked(&scratch, &into)).unwrap(),
            r#"{"outbreak":1,"written":1}"#
        );
        GzDecoder::new(fs::read(&into).unwrap().as_slice())
            .read_to_string(&mut text)
            .unwrap();
        assert_eq!(text, MAP);
    }

    #[test]
    fn answers_the_outline_of_the_map_of_the_report_a_request_names() {
        let scratch = Scratch::new("outlines");
        let answer = outline(&asked(&scratch, "unused")).unwrap();

        assert_eq!(
            answer,
            r#"{"outbreak":1,"outline":{"files":["/project/src/Alpha.php","/project/src/Beta.php","/project/src/Mid/Inner.php","/project/src/Zeta.php"],"tests":[["T\\C::c",[1,3]],["T\\A::a with data set \"x\\\"/é\"",[2,3]],["T\\D::d\u2028\u0001",[2,3]],["T\\B::b",[2,3]],["T\\Untouched::u",[]],["T\\Big::b",[]]]}}"#
        );
    }

    #[test]
    fn declines_a_report_it_does_not_read() {
        let scratch = Scratch::new("declines");
        fs::write(scratch.at("coverage.php"), b"<?php return [];").unwrap();
        let declined = r#"{"outbreak":1,"declined":"expected php-code-coverage's report in serialization format 4 at byte 0"}"#;

        assert_eq!(outline(&asked(&scratch, "unused")).unwrap(), declined);
        assert_eq!(
            convert(&asked(&scratch, &scratch.at("map.json.gz"))).unwrap(),
            declined
        );
        assert!(!fs::exists(scratch.at("map.json.gz")).unwrap());
    }

    #[test]
    fn fails_on_a_request_it_cannot_read_a_report_it_cannot_reach_or_a_map_it_cannot_write() {
        let scratch = Scratch::new("fails");
        let without_into = String::from_utf8(asked(&scratch, "x"))
            .unwrap()
            .replace(r#""into":"x","#, "");

        assert!(
            matches!(outline(b"[]").unwrap_err(), Failure::Usage(why) if why.starts_with("the report request is not one"))
        );
        assert!(
            matches!(convert(without_into.as_bytes()).unwrap_err(), Failure::Usage(why) if why == "the request names no map to write")
        );

        fs::remove_file(scratch.at("coverage.php")).unwrap();
        assert!(
            matches!(outline(&asked(&scratch, "x")).unwrap_err(), Failure::Io(why) if why.starts_with("cannot read "))
        );

        fs::write(scratch.at("coverage.php"), REPORT).unwrap();
        fs::write(scratch.at("blocking"), b"a file").unwrap();
        assert!(matches!(
            convert(&asked(&scratch, &scratch.at("blocking/map.json.gz"))).unwrap_err(),
            Failure::Io(why) if why.starts_with("cannot write ")
        ));
    }
}
