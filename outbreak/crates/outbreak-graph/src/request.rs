//! What the gate asks of the graph, as JSON on the binary's input:
//! `{"root": <directory>, "sources": [<path>, …], "tests": [<path>, …],
//! "reach": [<path>, …]}`, each path relative to the root, none leaving it,
//! and, each where it is asked, `"always"`: the sources every test loads
//! before it runs; `"given"`: `{<path>: <text>}`, files of the sources or the
//! tests read from the request in place of the disk, such as a deleted
//! file's last text; `"joined"`: the files whose joined files are asked
//! for; `"names"`: the files whose named files are asked for; and `"map"`:
//! `{<test file>: [<path>, …]}`, the files the coverage map shows each test
//! file running. Every key is read once, and none other is.

use std::collections::{BTreeMap, HashSet};

use outbreak_map::json::{Cursor, Key, Refused};

/// A request to the graph.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Request {
    /// The project's directory.
    pub root: String,
    /// The project's PHP files that are not tests.
    pub sources: Vec<String>,
    /// The project's test files.
    pub tests: Vec<String>,
    /// The files whose reaching test files are asked for.
    pub reach: Vec<String>,
    /// The sources every test loads before it runs.
    pub always: Vec<String>,
    /// The text of each file read from the request rather than the disk, by its path.
    pub given: BTreeMap<String, String>,
    /// The files whose joined files are asked for.
    pub joined: Vec<String>,
    /// The files whose named files are asked for.
    pub names: Vec<String>,
    /// The files the coverage map shows each test file running, by the test file's path.
    pub map: BTreeMap<String, Vec<String>>,
}

/// The request a text holds.
///
/// # Errors
///
/// Refuses a text that is not such a request, or a path that is absolute or leaves the root.
pub fn read(text: &[u8]) -> Result<Request, Refused> {
    let mut json = Cursor::over(text)?;
    let mut members = json.object()?;
    let mut seen = HashSet::new();
    let mut request = Request::default();
    let mut root = None;

    while let Some(key) = members.next(&mut json)? {
        if !seen.insert(key.text.clone()) {
            return Err(Refused { at: key.raw.start, expected: "each key once" });
        }

        match key.text.as_str() {
            "root" => root = Some(json.string()?),
            "sources" => request.sources = paths(&mut json)?,
            "tests" => request.tests = paths(&mut json)?,
            "reach" => request.reach = paths(&mut json)?,
            "always" => request.always = paths(&mut json)?,
            "given" => request.given = given(&mut json)?,
            "joined" => request.joined = paths(&mut json)?,
            "names" => request.names = paths(&mut json)?,
            "map" => request.map = mapped(&mut json)?,
            _ => return Err(Refused { at: key.raw.start, expected: "a key of the graph's request" }),
        }
    }

    json.end()?;

    let required = ["sources", "tests", "reach"].iter().all(|key| seen.contains(*key));

    match root {
        Some(root) if required => Ok(Request { root, ..request }),
        _ => Err(Refused { at: 0, expected: "a root, sources, tests and reach" }),
    }
}

fn paths(json: &mut Cursor<'_>) -> Result<Vec<String>, Refused> {
    let mut items = json.array()?;
    let mut paths = Vec::new();

    while items.next(json)? {
        paths.push(path(json)?);
    }

    Ok(paths)
}

fn path(json: &mut Cursor<'_>) -> Result<String, Refused> {
    let at = json.at();
    let path = json.string()?;

    if is_inside(&path) {
        Ok(path)
    } else {
        Err(Refused { at, expected: "a path relative to the root that stays inside it" })
    }
}

fn keyed_path(key: Key) -> Result<String, Refused> {
    if is_inside(&key.text) {
        Ok(key.text)
    } else {
        Err(Refused { at: key.raw.start, expected: "a path relative to the root that stays inside it" })
    }
}

fn given(json: &mut Cursor<'_>) -> Result<BTreeMap<String, String>, Refused> {
    let mut members = json.object()?;
    let mut texts = BTreeMap::new();

    while let Some(key) = members.next(json)? {
        let at = key.raw.start;
        let path = keyed_path(key)?;
        let text = json.string()?;

        if texts.insert(path, text).is_some() {
            return Err(Refused { at, expected: "each path once" });
        }
    }

    Ok(texts)
}

fn mapped(json: &mut Cursor<'_>) -> Result<BTreeMap<String, Vec<String>>, Refused> {
    let mut members = json.object()?;
    let mut map = BTreeMap::new();

    while let Some(key) = members.next(json)? {
        let at = key.raw.start;
        let test = keyed_path(key)?;
        let files = paths(json)?;

        if map.insert(test, files).is_some() {
            return Err(Refused { at, expected: "each path once" });
        }
    }

    Ok(map)
}

/// Whether a path is relative and none of its parts leaves the directory it is relative to.
#[must_use]
pub fn is_inside(path: &str) -> bool {
    !path.is_empty()
        && !path.starts_with('/')
        && !path.starts_with('\\')
        && !path.contains('\0')
        && path.split(['/', '\\']).all(|part| part != "..")
}

#[cfg(test)]
mod tests {
    use super::{is_inside, read};

    #[test]
    fn reads_a_request() {
        let request = read(br#"{"root": "/p", "sources": ["src/A.php"], "tests": ["tests/ATest.php"], "reach": ["src/A.php"]}"#);

        assert_eq!(request.map(|request| request.sources), Ok(vec!["src/A.php".to_owned()]));
    }

    #[test]
    fn reads_what_is_asked_beside_the_reach() {
        let request = read(
            br#"{"root": "/p", "sources": [], "tests": ["tests/ATest.php"], "reach": [],
                "always": ["tests/Pest.php"], "given": {"tests/ATest.php": "<?php"},
                "joined": ["tests/ATest.php"], "names": ["src/A.php"], "map": {"tests/ATest.php": ["src/A.php"]}}"#,
        );

        assert_eq!(
            request.map(|request| (request.always, request.given.len(), request.joined, request.names, request.map.len())),
            Ok((vec!["tests/Pest.php".to_owned()], 1, vec!["tests/ATest.php".to_owned()], vec!["src/A.php".to_owned()], 1)),
        );
    }

    #[test]
    fn refuses_paths_that_leave_the_root_and_unknown_or_repeated_keys() {
        for text in [
            r#"{"root": "/p", "sources": ["../x.php"], "tests": [], "reach": []}"#,
            r#"{"root": "/p", "sources": ["/etc/x.php"], "tests": [], "reach": []}"#,
            r#"{"root": "/p", "root": "/q", "sources": [], "tests": [], "reach": []}"#,
            r#"{"root": "/p", "sources": [], "tests": [], "reach": [], "other": 1}"#,
            r#"{"root": "/p", "sources": [], "tests": [], "reach": [], "given": {"../x.php": ""}}"#,
            r#"{"root": "/p", "sources": [], "tests": [], "reach": [], "map": {"/t.php": []}}"#,
            r#"{"root": "/p", "sources": [], "tests": [], "reach": [], "map": {"t.php": ["../x.php"]}}"#,
            r#"{"root": "/p", "sources": [], "tests": [], "reach": [], "given": {"a.php": "", "a.php": ""}}"#,
            r#"{"root": "/p", "sources": []}"#,
        ] {
            assert!(read(text.as_bytes()).is_err(), "{text}");
        }

        assert!(is_inside("src/a..b.php"));
    }
}
