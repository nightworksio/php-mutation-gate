//! Outbreak's static graph (ADR-0132): which test files can reach a file of
//! a PHP project by the names its files use, and which files those tests
//! reach, read without running any of them. The coverage map stays the
//! check: the gate adds or orders by what this answers, where a file on the
//! way holds a node the graph cannot place it answers nothing, and a test
//! file the map shows the graph wrong of gives no answer.

pub mod graph;
pub mod lexer;
pub mod names;
pub mod request;

use std::fmt::Write;
use std::fs;
use std::path::Path;

use graph::Graph;

/// The answer to a request on the binary's input, as JSON: each file asked
/// to be reached with the test files that reach it, each file asked to be
/// joined with the files the tests that reach it reach, and each file asked
/// to be named with the files it names, each `null` where the graph gives no
/// answer; each test file the coverage map shows the graph wrong of; and each
/// file the graph cannot place, with why.
///
/// # Errors
///
/// Says why where the request cannot be read; a file that cannot be read is
/// a file with no names, which holds a node the graph cannot place.
pub fn answer(text: &[u8], contract: u32) -> Result<String, String> {
    let request = request::read(text)
        .map_err(|refused| format!("the graph's request: expected {} at byte {}", refused.expected, refused.at))?;
    let root = Path::new(&request.root);

    Ok(answered(&request, |path| names_of(root, path, request.given.get(path)), contract))
}

/// The answer to a request, each file of the graph read by what it declares and names.
#[must_use]
pub fn answered(request: &request::Request, mut read: impl FnMut(&str) -> names::FileNames, contract: u32) -> String {
    let files = request
        .sources
        .iter()
        .map(|path| (path, false))
        .chain(request.tests.iter().map(|path| (path, true)))
        .map(|(path, test)| (path.clone(), read(path), test))
        .collect();
    let graph = Graph::of(files, &request.always).checked(&request.map);
    let mut out = format!(r#"{{"outbreak":{contract},"reaching":"#);
    answered_each(&mut out, &request.reach, |path| graph.reaching(path));
    out.push_str(r#","joined":"#);
    answered_each(&mut out, &request.joined, |path| graph.joined(path));
    out.push_str(r#","named":"#);
    answered_each(&mut out, &request.names, |path| graph.names(path));
    out.push_str(r#","wrong":"#);
    out.push_str(&listed(&graph.wrong()));
    out.push_str(r#","unplaced":{"#);

    for (at, (path, why)) in graph.unplaced().iter().enumerate() {
        let separator = if at == 0 { "" } else { "," };
        let _ = write!(out, "{separator}{}:{}", quoted(path), quoted(why));
    }

    out.push_str("}}");

    out
}

/// What a file declares and names: from the request's text of it where it
/// gives one, and from the disk otherwise.
#[must_use]
pub fn names_of(root: &Path, path: &str, given: Option<&String>) -> names::FileNames {
    given.map_or_else(
        || {
            fs::read(root.join(path)).map_or_else(
                |_| names::FileNames { unplaced: Some("a file that cannot be read"), ..names::FileNames::default() },
                |bytes| names::read(&bytes),
            )
        },
        |text| names::read(text.as_bytes()),
    )
}

/// Each path asked for with its answer, as a JSON object: a list of paths, or `null`.
fn answered_each(out: &mut String, asked: &[String], answer: impl Fn(&str) -> Option<Vec<String>>) {
    out.push('{');

    for (at, path) in asked.iter().enumerate() {
        let separator = if at == 0 { "" } else { "," };
        let _ = write!(out, "{separator}{}:", quoted(path));
        out.push_str(&answer(path).map_or_else(|| "null".to_owned(), |paths| listed(&paths)));
    }

    out.push('}');
}

/// Paths as a JSON list.
fn listed(paths: &[String]) -> String {
    let quoted: Vec<String> = paths.iter().map(|path| quoted(path)).collect();

    format!("[{}]", quoted.join(","))
}

/// A text as a JSON string.
#[must_use]
pub fn quoted(text: &str) -> String {
    let mut out = String::with_capacity(text.len() + 2);
    out.push('"');

    for character in text.chars() {
        match character {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            control if u32::from(control) < 0x20 => {
                let _ = write!(out, "\\u{:04x}", u32::from(control));
            }
            other => out.push(other),
        }
    }

    out.push('"');
    out
}

#[cfg(test)]
mod tests {
    use super::{answer, quoted};

    #[test]
    fn answers_each_question_from_the_given_texts_and_the_disk() {
        let request = br#"{"root": "/nowhere", "sources": ["src/Money.php", "src/Gone.php"],
            "tests": ["tests/MoneyTest.php"], "reach": ["src/Money.php"], "joined": ["tests/MoneyTest.php"],
            "names": ["tests/MoneyTest.php"], "map": {"tests/MoneyTest.php": ["src/Money.php"]},
            "given": {"src/Money.php": "<?php namespace App; final class Money {}",
                "tests/MoneyTest.php": "<?php use App\\Money; it('adds', fn () => new Money());"}}"#;

        assert_eq!(
            answer(request, 1),
            Ok(concat!(
                r#"{"outbreak":1,"reaching":{"src/Money.php":["tests/MoneyTest.php"]},"#,
                r#""joined":{"tests/MoneyTest.php":["src/Money.php"]},"#,
                r#""named":{"tests/MoneyTest.php":["src/Money.php"]},"wrong":[],"#,
                r#""unplaced":{"src/Gone.php":"a file that cannot be read"}}"#,
            )
            .to_owned()),
        );
    }

    #[test]
    fn says_why_a_request_cannot_be_read_and_answers_null_where_the_graph_cannot() {
        assert!(answer(b"{}", 1).is_err_and(|why| why.starts_with("the graph's request: expected")));
        assert_eq!(
            answer(br#"{"root": "/nowhere", "sources": [], "tests": [], "reach": ["a.php", "b.php"]}"#, 1),
            Ok(r#"{"outbreak":1,"reaching":{"a.php":null,"b.php":null},"joined":{},"named":{},"wrong":[],"unplaced":{}}"#.to_owned()),
        );
        assert_eq!(quoted("a\"b\\c\u{1}"), r#""a\"b\\c\u0001""#);
    }
}
