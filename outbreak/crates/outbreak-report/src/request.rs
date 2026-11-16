//! What the gate asks of a report, as compact JSON on the binary's input:
//!
//! ```json
//! {"report": <path>, "format": 4, "into": <path>,
//!  "paths": {<file as the report names it>: <its path in the project>, …},
//!  "timed": [[<test>, <seconds>], …],
//!  "loads": [[[<test>, [<package>, …], <started a process>], …], …],
//!  "leaving": {<test>: [<path it stays on>, …], …},
//!  "measured": {<member>, …}, "keys": {…}}
//! ```
//!
//! `report` and `format`, the serialization format the gate's own
//! php-code-coverage reads, are always asked for; an outline needs `timed`
//! besides, and a map every key but `leaving` and `keys`, which it holds where
//! they are asked for. The gate decides every one of them: what each file's
//! path is, which tests the run timed beside the report and what they loaded,
//! which tests leave the map and the files each stays on, and where the map
//! was measured and its entries' keys, whose JSON is copied as it stands. Every
//! key is read once, and none other is.

use std::collections::{HashMap, HashSet};
use std::ops::Range;

use outbreak_map::json::{Cursor, Key, Number, Refused};

/// One test's record of what it loaded.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Load {
    /// The test.
    pub test: String,
    /// Where its list of packages stands in the request.
    pub packages: Range<usize>,
    /// Where whether it started a process stands in the request.
    pub started: Range<usize>,
}

/// A request, its JSON values that are copied kept as where they stand in it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Asked<'r> {
    /// The request's own text.
    pub text: &'r [u8],
    /// The report to read.
    pub report: String,
    /// The serialization format the gate reads.
    pub format: u64,
    /// Where the map is written.
    pub into: Option<String>,
    /// Each file's path in the project, by its path as the report names it.
    pub paths: HashMap<String, String>,
    /// Each test the run timed beside the report, with where its seconds stand, in their order.
    pub timed: Vec<(String, Range<usize>)>,
    /// What each process's tests loaded.
    pub loads: Vec<Vec<Load>>,
    /// Each test that leaves the map, with the paths of the files it stays on; none where no
    /// admission is asked for.
    pub leaving: Option<HashMap<String, HashSet<String>>>,
    /// Where each member of where the map was measured stands.
    pub measured: Vec<Range<usize>>,
    /// Where the entries' keys stand, where they are asked for.
    pub keys: Option<Range<usize>>,
}

/// The request a text holds.
///
/// # Errors
///
/// Refuses a text that is not such a request, or not compact.
pub fn read(text: &[u8]) -> Result<Asked<'_>, Refused> {
    let mut json = Cursor::over(text)?;
    let mut members = json.object()?;
    let mut seen = HashSet::new();
    let mut asked = Asked {
        text,
        report: String::new(),
        format: 0,
        into: None,
        paths: HashMap::new(),
        timed: Vec::new(),
        loads: Vec::new(),
        leaving: None,
        measured: Vec::new(),
        keys: None,
    };

    while let Some(key) = members.next(&mut json)? {
        member(&mut json, once(&mut seen, key)?.as_str(), &mut asked)?;
    }

    json.end()?;

    if json.spaced() {
        return Err(Refused {
            at: 0,
            expected: "compact JSON",
        });
    }

    if seen.contains("report") && seen.contains("format") {
        Ok(asked)
    } else {
        Err(Refused {
            at: 0,
            expected: "a report and its format",
        })
    }
}

fn member<'r>(json: &mut Cursor<'r>, key: &str, asked: &mut Asked<'r>) -> Result<(), Refused> {
    match key {
        "report" => asked.report = json.string()?,
        "format" => asked.format = whole(json)?,
        "into" => asked.into = Some(json.string()?),
        "paths" => asked.paths = paths(json)?,
        "timed" => asked.timed = timed(json)?,
        "loads" => asked.loads = loads(json)?,
        "leaving" => asked.leaving = Some(leaving(json)?),
        "measured" => asked.measured = measured(json)?,
        "keys" => asked.keys = Some(object(json)?),
        _ => {
            return Err(Refused {
                at: json.at(),
                expected: "a key a request holds",
            });
        }
    }

    Ok(())
}

fn whole(json: &mut Cursor<'_>) -> Result<u64, Refused> {
    let at = json.value_start();

    match json.number()? {
        Number::Whole(value) => Ok(value),
        Number::Other { .. } => Err(Refused {
            at,
            expected: "a whole number",
        }),
    }
}

fn paths(json: &mut Cursor<'_>) -> Result<HashMap<String, String>, Refused> {
    let mut members = json.object()?;
    let mut seen = HashSet::new();
    let mut paths = HashMap::new();

    while let Some(key) = members.next(json)? {
        let file = once(&mut seen, key)?;
        paths.insert(file, json.string()?);
    }

    Ok(paths)
}

fn timed(json: &mut Cursor<'_>) -> Result<Vec<(String, Range<usize>)>, Refused> {
    let mut items = json.array()?;
    let mut timed = Vec::new();

    while items.next(json)? {
        let mut pair = json.array()?;
        let test = item(json, &mut pair)?.string()?;
        item(json, &mut pair)?;
        let start = json.value_start();
        json.number()?;
        timed.push((test, start..json.at()));
        last(json, &mut pair)?;
    }

    Ok(timed)
}

fn loads(json: &mut Cursor<'_>) -> Result<Vec<Vec<Load>>, Refused> {
    let mut processes = json.array()?;
    let mut loads = Vec::new();

    while processes.next(json)? {
        let mut tests = json.array()?;
        let mut process = Vec::new();

        while tests.next(json)? {
            let mut record = json.array()?;
            let test = item(json, &mut record)?.string()?;
            item(json, &mut record)?;
            let packages = json.value_start();
            let mut names = json.array()?;

            while names.next(json)? {
                json.string()?;
            }

            let packages = packages..json.at();
            item(json, &mut record)?;
            let started = json.value_start();
            json.boolean()?;
            process.push(Load {
                test,
                packages,
                started: started..json.at(),
            });
            last(json, &mut record)?;
        }

        loads.push(process);
    }

    Ok(loads)
}

fn leaving(json: &mut Cursor<'_>) -> Result<HashMap<String, HashSet<String>>, Refused> {
    let mut members = json.object()?;
    let mut seen = HashSet::new();
    let mut leaving = HashMap::new();

    while let Some(key) = members.next(json)? {
        let test = once(&mut seen, key)?;
        let mut items = json.array()?;
        let mut files = HashSet::new();

        while items.next(json)? {
            files.insert(json.string()?);
        }

        leaving.insert(test, files);
    }

    Ok(leaving)
}

/// Where each member of an object stands, its key with it.
fn measured(json: &mut Cursor<'_>) -> Result<Vec<Range<usize>>, Refused> {
    let mut members = json.object()?;
    let mut seen = HashSet::new();
    let mut measured = Vec::new();

    while let Some(key) = members.next(json)? {
        let start = key.raw.start;
        once(&mut seen, key)?;
        json.skip()?;
        measured.push(start..json.at());
    }

    Ok(measured)
}

/// Where an object stands.
fn object(json: &mut Cursor<'_>) -> Result<Range<usize>, Refused> {
    let start = json.value_start();

    if json.bytes(start..start + 1) != b"{" {
        return Err(Refused {
            at: start,
            expected: "an object",
        });
    }

    json.spanned()
}

/// The cursor at the next item of a list that must have one.
fn item<'c, 'r>(
    json: &'c mut Cursor<'r>,
    items: &mut outbreak_map::json::Items,
) -> Result<&'c mut Cursor<'r>, Refused> {
    if items.next(json)? {
        Ok(json)
    } else {
        Err(Refused {
            at: json.at(),
            expected: "another item",
        })
    }
}

/// The end of a list that must have no more items.
fn last(json: &mut Cursor<'_>, items: &mut outbreak_map::json::Items) -> Result<(), Refused> {
    if items.next(json)? {
        Err(Refused {
            at: json.at(),
            expected: "the list's end",
        })
    } else {
        Ok(())
    }
}

fn once(seen: &mut HashSet<String>, key: Key) -> Result<String, Refused> {
    if seen.insert(key.text.clone()) {
        Ok(key.text)
    } else {
        Err(Refused {
            at: key.raw.start,
            expected: "each key once",
        })
    }
}

#[cfg(test)]
mod tests {
    use super::read;

    #[test]
    fn reads_every_key_a_request_holds() {
        let text = br#"{"report":"/r/coverage.php","format":4,"into":"/r/map.json.gz","paths":{"/r/src/A.php":"src/A.php"},"timed":[["T::a",0.5],["T::b",2]],"loads":[[["T::a",["v/p"],true]],[]],"leaving":{"T::a":["src/A.php","src/B.php"],"T::b":[]},"measured":{"commit":"c","dirty":true},"keys":{"t/ATest.php":"k"}}"#;
        let asked = read(text).unwrap();
        let raw = |range: std::ops::Range<usize>| std::str::from_utf8(&text[range]).unwrap();

        assert_eq!(asked.report, "/r/coverage.php");
        assert_eq!(asked.format, 4);
        assert_eq!(asked.into.as_deref(), Some("/r/map.json.gz"));
        assert_eq!(asked.paths["/r/src/A.php"], "src/A.php");
        assert_eq!(asked.timed.len(), 2);
        assert_eq!(raw(asked.timed[1].1.clone()), "2");
        assert_eq!(asked.loads.len(), 2);
        assert_eq!(raw(asked.loads[0][0].packages.clone()), r#"["v/p"]"#);
        assert_eq!(raw(asked.loads[0][0].started.clone()), "true");
        assert_eq!(asked.leaving.as_ref().unwrap()["T::a"].len(), 2);
        assert!(asked.leaving.as_ref().unwrap()["T::b"].is_empty());
        let measured: Vec<&str> = asked
            .measured
            .iter()
            .map(|range| raw(range.clone()))
            .collect();
        assert_eq!(measured, [r#""commit":"c""#, r#""dirty":true"#]);
        assert_eq!(raw(asked.keys.clone().unwrap()), r#"{"t/ATest.php":"k"}"#);
    }

    #[test]
    fn asks_for_no_admission_or_keys_where_it_holds_none() {
        let asked = read(br#"{"report":"r","format":4}"#).unwrap();

        assert!(asked.leaving.is_none() && asked.keys.is_none() && asked.into.is_none());
        assert!(
            asked.paths.is_empty()
                && asked.timed.is_empty()
                && asked.loads.is_empty()
                && asked.measured.is_empty()
        );
    }

    #[test]
    fn refuses_any_other_request() {
        for (text, expected) in [
            (&br#"{"report":"r"}"#[..], "a report and its format"),
            (br#"{"format":4}"#, "a report and its format"),
            (br#"{"report": "r","format":4}"#, "compact JSON"),
            (
                br#"{"report":"r","format":4,"other":1}"#,
                "a key a request holds",
            ),
            (
                br#"{"report":"r","report":"s","format":4}"#,
                "each key once",
            ),
            (br#"{"report":"r","format":4.5}"#, "a whole number"),
            (
                br#"{"report":"r","format":4,"paths":{"a":"b","a":"c"}}"#,
                "each key once",
            ),
            (
                br#"{"report":"r","format":4,"timed":[["T::a"]]}"#,
                "another item",
            ),
            (
                br#"{"report":"r","format":4,"timed":[["T::a",1,2]]}"#,
                "the list's end",
            ),
            (
                br#"{"report":"r","format":4,"timed":[["T::a","1"]]}"#,
                "a number",
            ),
            (
                br#"{"report":"r","format":4,"loads":[[["T::a",[1],true]]]}"#,
                "text",
            ),
            (
                br#"{"report":"r","format":4,"loads":[[["T::a",[],1]]]}"#,
                "true or false",
            ),
            (
                br#"{"report":"r","format":4,"leaving":{"T::a":[1]}}"#,
                "text",
            ),
            (
                br#"{"report":"r","format":4,"leaving":{"T::a":[],"T::a":[]}}"#,
                "each key once",
            ),
            (
                br#"{"report":"r","format":4,"measured":{"commit":"c","commit":"d"}}"#,
                "each key once",
            ),
            (br#"{"report":"r","format":4,"keys":[]}"#, "an object"),
            (br#"{"report":"r","format":4}x"#, "the end of the text"),
        ] {
            let shown = String::from_utf8_lossy(text);

            assert_eq!(read(text).unwrap_err().expected, expected, "{shown}");
        }
    }
}
