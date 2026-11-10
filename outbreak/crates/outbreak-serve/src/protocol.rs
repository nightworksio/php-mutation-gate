//! What the daemon answers (ADR-0133, decision 5): one request a line, a verb
//! and its JSON, and one line of JSON in contract 1 for each.
//!
//! - `contract` says the contract it speaks.
//! - `proofs {"keys": [<key>, …]}` answers `{"proofs": {<key>: <text>, …}}`,
//!   each key it holds a proof of.
//! - `keep {"proofs": {<key>: <text>, …}}` holds each proof, journals it, and
//!   answers `{"kept": <count>}`.
//! - `graph <request>` answers a request of the static graph as `outbreak
//!   graph reach` does (ADR-0132), each file read for its names again only
//!   where its bytes changed.
//! - `stop` answers `{"stopping": true}`, and the daemon stops.
//!
//! Anything else is answered `{"refused": <why>}`.

use std::collections::BTreeSet;
use std::fmt::Write as _;

use outbreak_graph::{names::FileNames, quoted, request};
use outbreak_map::json::{Cursor, Refused};

use crate::journal::{Kind, Record};
use crate::state::State;

/// What a request's answer is, what it keeps, and whether the daemon stops after it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Answer {
    /// The answer's line, without its end.
    pub line: String,
    /// The records the request keeps, for the journal.
    pub kept: Vec<Record>,
    /// Whether the daemon stops once it answered.
    pub stops: bool,
}

impl Answer {
    fn said(line: String) -> Self {
        Self { line, kept: Vec::new(), stops: false }
    }
}

/// The answer to one request line, the state changed by what it keeps; each
/// file of a graph read by `read`, which gives its bytes where it has them.
pub fn answer(state: &mut State, line: &str, contract: u32, read: impl Fn(&str, &str) -> Option<Vec<u8>>) -> Answer {
    let (verb, json) = line.split_once(' ').unwrap_or((line, ""));
    let answered = match verb {
        "contract" => Ok(Answer::said(format!(r#"{{"outbreak":{contract},"serving":true}}"#))),
        "proofs" => proofs(state, json.as_bytes(), contract),
        "keep" => kept(state, json.as_bytes(), contract),
        "graph" => graph(state, json.as_bytes(), contract, &read),
        "stop" => Ok(Answer { stops: true, ..Answer::said(format!(r#"{{"outbreak":{contract},"stopping":true}}"#)) }),
        _ => Err(format!("{verb} is no request the daemon answers")),
    };

    answered.unwrap_or_else(|why| Answer::said(format!(r#"{{"outbreak":{contract},"refused":{}}}"#, quoted(&why))))
}

fn proofs(state: &State, json: &[u8], contract: u32) -> Result<Answer, String> {
    let keys = only(json, "keys", |cursor| {
        let mut items = cursor.array()?;
        let mut keys = BTreeSet::new();

        while items.next(cursor)? {
            keys.insert(cursor.string()?);
        }

        Ok(keys)
    })?;
    let mut line = format!(r#"{{"outbreak":{contract},"proofs":{{"#);
    let mut first = true;

    for key in &keys {
        if let Some(proof) = state.proof(key) {
            let separator = if first { "" } else { "," };
            let _ = write!(line, "{separator}{}:{}", quoted(key), quoted(&String::from_utf8_lossy(proof)));
            first = false;
        }
    }

    line.push_str("}}");

    Ok(Answer::said(line))
}

fn kept(state: &mut State, json: &[u8], contract: u32) -> Result<Answer, String> {
    let proofs = only(json, "proofs", |cursor| {
        let mut members = cursor.object()?;
        let mut proofs = Vec::new();

        while let Some(key) = members.next(cursor)? {
            proofs.push(Record { kind: Kind::Proof, key: key.text, value: cursor.string()?.into_bytes() });
        }

        Ok(proofs)
    })?;

    for record in &proofs {
        state.apply(record.clone());
    }

    Ok(Answer {
        line: format!(r#"{{"outbreak":{contract},"kept":{}}}"#, proofs.len()),
        kept: proofs,
        stops: false,
    })
}

fn graph(
    state: &mut State,
    json: &[u8],
    contract: u32,
    read: &impl Fn(&str, &str) -> Option<Vec<u8>>,
) -> Result<Answer, String> {
    let asked = request::read(json).map_err(|refused| said(&refused))?;
    let line = outbreak_graph::answered(
        &asked,
        |path| match asked.given.get(path) {
            Some(text) => state.names(path, text.as_bytes()),
            None => read(&asked.root, path).map_or_else(
                || FileNames { unplaced: Some("a file that cannot be read"), ..FileNames::default() },
                |bytes| state.names(path, &bytes),
            ),
        },
        contract,
    );

    Ok(Answer::said(line))
}

/// The one member of an object a request is, as `value` reads it.
fn only<T>(json: &[u8], name: &str, value: impl FnOnce(&mut Cursor<'_>) -> Result<T, Refused>) -> Result<T, String> {
    let mut cursor = Cursor::over(json).map_err(|refused| said(&refused))?;
    let mut members = cursor.object().map_err(|refused| said(&refused))?;
    let key = members.next(&mut cursor).map_err(|refused| said(&refused))?;

    if key.as_ref().map(|key| key.text.as_str()) != Some(name) {
        return Err(format!("the request: expected {name}"));
    }

    let read = value(&mut cursor).map_err(|refused| said(&refused))?;
    let end = members.next(&mut cursor).map_err(|refused| said(&refused))?;

    if end.is_some() {
        return Err(format!("the request: expected {name} alone"));
    }

    cursor.end().map_err(|refused| said(&refused))?;

    Ok(read)
}

fn said(refused: &Refused) -> String {
    format!("the request: expected {} at byte {}", refused.expected, refused.at)
}

#[cfg(test)]
mod tests {
    use super::answer;
    use crate::state::State;

    fn asked(state: &mut State, line: &str) -> String {
        answer(state, line, 1, |_, path| (path == "src/A.php").then(|| b"<?php class A {}".to_vec())).line
    }

    #[test]
    fn keeps_proofs_and_answers_those_it_holds() {
        let mut state = State::default();
        let kept = answer(&mut state, r#"keep {"proofs": {"k1": "one", "k2": "two"}}"#, 1, |_, _| None);

        assert_eq!(kept.line, r#"{"outbreak":1,"kept":2}"#);
        assert_eq!(kept.kept.len(), 2);
        assert_eq!(asked(&mut state, r#"proofs {"keys": ["k2", "k3", "k1"]}"#), r#"{"outbreak":1,"proofs":{"k1":"one","k2":"two"}}"#);
    }

    #[test]
    fn answers_the_graph_from_the_files_it_reads() {
        let mut state = State::default();
        let line = asked(
            &mut state,
            r#"graph {"root": "/p", "sources": ["src/A.php", "src/Gone.php"], "tests": ["tests/ATest.php"], "reach": ["src/A.php"], "given": {"tests/ATest.php": "<?php new A();"}}"#,
        );

        assert!(line.starts_with(r#"{"outbreak":1,"reaching":{"src/A.php":["tests/ATest.php"]}"#), "{line}");
        assert!(line.contains(r#""src/Gone.php":"a file that cannot be read""#), "{line}");
    }

    #[test]
    fn says_its_contract_stops_and_refuses_anything_else() {
        let mut state = State::default();

        assert_eq!(asked(&mut state, "contract"), r#"{"outbreak":1,"serving":true}"#);
        assert!(answer(&mut state, "stop", 1, |_, _| None).stops);

        for line in ["other", "proofs []", r#"proofs {"other": []}"#, r#"proofs {"keys": [], "more": 1}"#, r#"proofs {"keys": []} x"#, "graph {}", r#"keep {"proofs": {"k": 1}}"#] {
            assert!(asked(&mut state, line).contains(r#""refused":"#), "{line}");
        }
    }
}
