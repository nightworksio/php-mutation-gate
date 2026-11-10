//! Outbreak's static graph (ADR-0132): which test files can reach a file of
//! a PHP project by the names its files use, read without running any of
//! them. The coverage map stays the check: the gate adds or orders by what
//! this answers, and where a file on the way holds a node the graph cannot
//! place, it answers nothing.

pub mod graph;
pub mod lexer;
pub mod names;
pub mod request;

use std::fmt::Write;
use std::fs;
use std::path::Path;

use graph::Graph;

/// The answer to a request on the binary's input, as JSON: each file asked
/// for with the test files that reach it, or `null`, and each file the graph
/// cannot place, with why.
///
/// # Errors
///
/// Says why where the request cannot be read; a file that cannot be read is
/// a file with no names, which holds a node the graph cannot place.
pub fn answer(text: &[u8], contract: u32) -> Result<String, String> {
    let request = request::read(text)
        .map_err(|refused| format!("the graph's request: expected {} at byte {}", refused.expected, refused.at))?;
    let root = Path::new(&request.root);
    let files = request
        .sources
        .iter()
        .map(|path| (path, false))
        .chain(request.tests.iter().map(|path| (path, true)))
        .map(|(path, test)| {
            let names = fs::read(root.join(path)).map_or_else(
                |_| names::FileNames { unplaced: Some("a file that cannot be read"), ..names::FileNames::default() },
                |bytes| names::read(&bytes),
            );
            (path.clone(), names, test)
        })
        .collect();
    let graph = Graph::of(files);
    let mut out = format!(r#"{{"outbreak":{contract},"reaching":{{"#);

    for (at, path) in request.reach.iter().enumerate() {
        let separator = if at == 0 { "" } else { "," };
        let _ = write!(out, "{separator}{}:", quoted(path));

        match graph.reaching(path) {
            Some(tests) => {
                let listed: Vec<String> = tests.iter().map(|test| quoted(test)).collect();
                let _ = write!(out, "[{}]", listed.join(","));
            }
            None => out.push_str("null"),
        }
    }

    out.push_str(r#"},"unplaced":{"#);

    for (at, (path, why)) in graph.unplaced().iter().enumerate() {
        let separator = if at == 0 { "" } else { "," };
        let _ = write!(out, "{separator}{}:{}", quoted(path), quoted(why));
    }

    out.push_str("}}");

    Ok(out)
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
