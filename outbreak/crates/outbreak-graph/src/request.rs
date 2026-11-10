//! What the gate asks of the graph, as JSON on the binary's input:
//! `{"root": <directory>, "sources": [<path>, …], "tests": [<path>, …],
//! "reach": [<path>, …]}`, each path relative to the root, none leaving it.
//! Every key is read once, and none other is.

use std::collections::HashSet;

use outbreak_map::json::{Cursor, Refused};

/// A request to the graph.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    /// The project's directory.
    pub root: String,
    /// The project's PHP files that are not tests.
    pub sources: Vec<String>,
    /// The project's test files.
    pub tests: Vec<String>,
    /// The files whose reaching test files are asked for.
    pub reach: Vec<String>,
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
    let (mut root, mut sources, mut tests, mut reach) = (None, None, None, None);

    while let Some(key) = members.next(&mut json)? {
        if !seen.insert(key.text.clone()) {
            return Err(Refused { at: key.raw.start, expected: "each key once" });
        }

        match key.text.as_str() {
            "root" => root = Some(json.string()?),
            "sources" => sources = Some(paths(&mut json)?),
            "tests" => tests = Some(paths(&mut json)?),
            "reach" => reach = Some(paths(&mut json)?),
            _ => return Err(Refused { at: key.raw.start, expected: "a key of the graph's request" }),
        }
    }

    json.end()?;

    match (root, sources, tests, reach) {
        (Some(root), Some(sources), Some(tests), Some(reach)) => Ok(Request { root, sources, tests, reach }),
        _ => Err(Refused { at: 0, expected: "a root, sources, tests and reach" }),
    }
}

fn paths(json: &mut Cursor<'_>) -> Result<Vec<String>, Refused> {
    let mut items = json.array()?;
    let mut paths = Vec::new();

    while items.next(json)? {
        let at = json.at();
        let path = json.string()?;

        if !is_inside(&path) {
            return Err(Refused { at, expected: "a path relative to the root that stays inside it" });
        }

        paths.push(path);
    }

    Ok(paths)
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
    fn refuses_paths_that_leave_the_root_and_unknown_or_repeated_keys() {
        for text in [
            r#"{"root": "/p", "sources": ["../x.php"], "tests": [], "reach": []}"#,
            r#"{"root": "/p", "sources": ["/etc/x.php"], "tests": [], "reach": []}"#,
            r#"{"root": "/p", "root": "/q", "sources": [], "tests": [], "reach": []}"#,
            r#"{"root": "/p", "sources": [], "tests": [], "reach": [], "other": 1}"#,
            r#"{"root": "/p", "sources": []}"#,
        ] {
            assert!(read(text.as_bytes()).is_err(), "{text}");
        }

        assert!(is_inside("src/a..b.php"));
    }
}
