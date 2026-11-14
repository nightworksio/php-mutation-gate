//! What the daemon answers (ADR-0133, decision 5): one request a line, a verb
//! and its JSON, and one line of JSON in contract 1 for each.
//!
//! - `contract` says the contract it speaks.
//! - `proofs {"keys": [<key>, …]}` answers `{"proofs": {<key>: <text>, …}}`,
//!   each key it holds a proof of.
//! - `keep {"proofs": {<key>: <text>, …}}` holds each proof, journals it, and
//!   answers `{"kept": <count>}`.
//! - `forget {"keys": [<key>, …]}` holds no proof under each key, journals
//!   that, and answers `{"forgot": <count>}`.
//! - `graph <request>` answers a request of the static graph as `outbreak
//!   graph reach` does (ADR-0132), each file read for its names again only
//!   where its bytes changed.
//! - `changed {"paths": [<directory>, …], "since": <generation>, "wait":
//!   <milliseconds>}` watches each directory, relative to the root, and
//!   answers `{"changed": <generation>}` once a change settled past `since`,
//!   or once the wait passed; `{"unwatched": <why>}` where it watches nothing
//!   (decision 7). The server answers it, outside the state's lock.
//! - `power {"since": <generation>, "wait": <milliseconds>}` answers the same
//!   of the machine's power source (decision 8).
//! - `stop` answers `{"stopping": true}`, and the daemon stops.
//!
//! Anything else is answered `{"refused": <why>}`.

use std::collections::BTreeSet;
use std::fmt::Write as _;
use std::time::Duration;

use outbreak_graph::{names::FileNames, quoted, request};
use outbreak_map::json::{Cursor, Number, Refused};

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
        Self {
            line,
            kept: Vec::new(),
            stops: false,
        }
    }
}

/// What a client waits for a change of.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Watched {
    /// The files beneath these directories, each relative to the root.
    Files(Vec<String>),
    /// The machine's power source.
    Power,
}

/// What a client waiting for a change asks.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Waiting {
    /// What it waits for a change of.
    pub watched: Watched,
    /// The generation of changes it saw last.
    pub since: u64,
    /// How long it waits for a change, at most.
    pub wait: Duration,
}

/// The verb a client waiting for a change of the files asks with.
const CHANGED: &str = "changed";

/// The verb a client waiting for a change of the power source asks with.
const POWER: &str = "power";

/// The request a line is where it waits for a change, or why it cannot be
/// read; none where it asks something else.
#[must_use]
pub fn waiting(line: &str) -> Option<Result<Waiting, String>> {
    let (verb, json) = line.split_once(' ').unwrap_or((line, ""));

    match verb {
        CHANGED => Some(waited(json.as_bytes(), true)),
        POWER => Some(waited(json.as_bytes(), false)),
        _ => None,
    }
}

/// The answer to a client waiting for a change: the generation of changes now.
#[must_use]
pub fn changed(generation: u64, contract: u32) -> String {
    format!(r#"{{"outbreak":{contract},"changed":{generation}}}"#)
}

/// The answer to a client waiting for a change where the daemon watches nothing, and why.
#[must_use]
pub fn unwatched(why: &str, contract: u32) -> String {
    format!(r#"{{"outbreak":{contract},"unwatched":{}}}"#, quoted(why))
}

/// The answer to a request it refuses, and why.
#[must_use]
pub fn refused(why: &str, contract: u32) -> String {
    format!(r#"{{"outbreak":{contract},"refused":{}}}"#, quoted(why))
}

/// The answer to one request line, the state changed by what it keeps; each
/// file of a graph read by `read`, which gives its bytes where it has them.
pub fn answer(
    state: &mut State,
    line: &str,
    contract: u32,
    read: impl Fn(&str, &str) -> Option<Vec<u8>>,
) -> Answer {
    let (verb, json) = line.split_once(' ').unwrap_or((line, ""));
    let answered = match verb {
        "contract" => Ok(Answer::said(format!(
            r#"{{"outbreak":{contract},"serving":true}}"#
        ))),
        "proofs" => proofs(state, json.as_bytes(), contract),
        "keep" => kept(state, json.as_bytes(), contract),
        "forget" => forgot(state, json.as_bytes(), contract),
        "graph" => graph(state, json.as_bytes(), contract, &read),
        "stop" => Ok(Answer {
            stops: true,
            ..Answer::said(format!(r#"{{"outbreak":{contract},"stopping":true}}"#))
        }),
        _ => Err(format!("{verb} is no request the daemon answers")),
    };

    answered.unwrap_or_else(|why| Answer::said(refused(&why, contract)))
}

fn keys(json: &[u8]) -> Result<BTreeSet<String>, String> {
    only(json, "keys", |cursor| {
        Ok(strings(cursor)?.into_iter().collect())
    })
}

fn proofs(state: &State, json: &[u8], contract: u32) -> Result<Answer, String> {
    let keys = keys(json)?;
    let mut line = format!(r#"{{"outbreak":{contract},"proofs":{{"#);
    let mut first = true;

    for key in &keys {
        if let Some(proof) = state.proof(key) {
            let separator = if first { "" } else { "," };
            let _ = write!(
                line,
                "{separator}{}:{}",
                quoted(key),
                quoted(&String::from_utf8_lossy(proof))
            );
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
            proofs.push(Record {
                kind: Kind::Proof,
                key: key.text,
                value: cursor.string()?.into_bytes(),
            });
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

fn forgot(state: &mut State, json: &[u8], contract: u32) -> Result<Answer, String> {
    let forgotten: Vec<Record> = keys(json)?
        .into_iter()
        .map(|key| Record {
            kind: Kind::Forgotten,
            key,
            value: Vec::new(),
        })
        .collect();

    for record in &forgotten {
        state.apply(record.clone());
    }

    Ok(Answer {
        line: format!(r#"{{"outbreak":{contract},"forgot":{}}}"#, forgotten.len()),
        kept: forgotten,
        stops: false,
    })
}

fn graph(
    state: &mut State,
    json: &[u8],
    contract: u32,
    read: &impl Fn(&str, &str) -> Option<Vec<u8>>,
) -> Result<Answer, String> {
    let asked = request::read(json).map_err(said)?;
    let line = outbreak_graph::answered(
        &asked,
        |path| match asked.given.get(path) {
            Some(text) => state.names(path, text.as_bytes()),
            None => read(&asked.root, path).map_or_else(
                || FileNames {
                    unplaced: Some("a file that cannot be read"),
                    ..FileNames::default()
                },
                |bytes| state.names(path, &bytes),
            ),
        },
        contract,
    );

    Ok(Answer::said(line))
}

fn waited(json: &[u8], files: bool) -> Result<Waiting, String> {
    let mut cursor = Cursor::over(json).map_err(said)?;
    let mut members = cursor.object().map_err(said)?;
    let (mut paths, mut since, mut wait) = (None, None, None);

    while let Some(key) = members.next(&mut cursor).map_err(said)? {
        match key.text.as_str() {
            "paths" if files => {
                paths = Some(strings(&mut cursor).map_err(said)?);
            }
            "since" => since = Some(whole(&mut cursor, "since")?),
            "wait" => wait = Some(Duration::from_millis(whole(&mut cursor, "wait")?)),
            other => return Err(format!("the request: expected no {other}")),
        }
    }

    cursor.end().map_err(said)?;

    let watched = match paths {
        Some(paths) => Watched::Files(paths),
        None if files => return Err(String::from("the request: expected paths")),
        None => Watched::Power,
    };

    match (since, wait) {
        (Some(since), Some(wait)) => Ok(Waiting {
            watched,
            since,
            wait,
        }),
        _ => Err(String::from("the request: expected since and wait")),
    }
}

fn strings(cursor: &mut Cursor<'_>) -> Result<Vec<String>, Refused> {
    let mut items = cursor.array()?;
    let mut strings = Vec::new();

    while items.next(cursor)? {
        strings.push(cursor.string()?);
    }

    Ok(strings)
}

fn whole(cursor: &mut Cursor<'_>, name: &str) -> Result<u64, String> {
    match cursor.number().map_err(said)? {
        Number::Whole(value) => Ok(value),
        Number::Other { .. } => Err(format!("the request: expected {name} as a whole number")),
    }
}

/// The one member of an object a request is, as `value` reads it.
fn only<T>(
    json: &[u8],
    name: &str,
    value: impl FnOnce(&mut Cursor<'_>) -> Result<T, Refused>,
) -> Result<T, String> {
    let mut cursor = Cursor::over(json).map_err(said)?;
    let mut members = cursor.object().map_err(said)?;
    let key = members.next(&mut cursor).map_err(said)?;

    if key.as_ref().map(|key| key.text.as_str()) != Some(name) {
        return Err(format!("the request: expected {name}"));
    }

    let read = value(&mut cursor).map_err(said)?;
    let end = members.next(&mut cursor).map_err(said)?;

    if end.is_some() {
        return Err(format!("the request: expected {name} alone"));
    }

    cursor.end().map_err(said)?;

    Ok(read)
}

#[expect(
    clippy::needless_pass_by_value,
    reason = "map_err hands each refusal over by value"
)]
fn said(refused: Refused) -> String {
    format!(
        "the request: expected {} at byte {}",
        refused.expected, refused.at
    )
}

#[cfg(test)]
mod tests {
    use std::time::Duration;

    use super::{Waiting, Watched, answer, changed, unwatched, waiting};
    use crate::state::State;

    fn answered(state: &mut State, line: &str) -> super::Answer {
        answer(state, line, 1, |_, path| {
            (path == "src/A.php").then(|| b"<?php class A {}".to_vec())
        })
    }

    fn asked(state: &mut State, line: &str) -> String {
        answered(state, line).line
    }

    #[test]
    fn keeps_proofs_and_answers_those_it_holds() {
        let mut state = State::default();
        let kept = answered(&mut state, r#"keep {"proofs": {"k1": "one", "k2": "two"}}"#);

        assert_eq!(kept.line, r#"{"outbreak":1,"kept":2}"#);
        assert_eq!(kept.kept.len(), 2);
        assert_eq!(
            asked(&mut state, r#"proofs {"keys": ["k2", "k3", "k1"]}"#),
            r#"{"outbreak":1,"proofs":{"k1":"one","k2":"two"}}"#
        );

        let forgot = answered(&mut state, r#"forget {"keys": ["k1", "k3"]}"#);

        assert_eq!(forgot.line, r#"{"outbreak":1,"forgot":2}"#);
        assert_eq!(forgot.kept.len(), 2);
        assert_eq!(
            asked(&mut state, r#"proofs {"keys": ["k1", "k2"]}"#),
            r#"{"outbreak":1,"proofs":{"k2":"two"}}"#
        );
    }

    #[test]
    fn answers_the_graph_from_the_files_it_reads() {
        let mut state = State::default();
        let line = asked(
            &mut state,
            r#"graph {"root": "/p", "sources": ["src/A.php", "src/Gone.php"], "tests": ["tests/ATest.php"], "reach": ["src/A.php"], "given": {"tests/ATest.php": "<?php new A();"}}"#,
        );

        assert!(
            line.starts_with(r#"{"outbreak":1,"reaching":{"src/A.php":["tests/ATest.php"]}"#),
            "{line}"
        );
        assert!(
            line.contains(r#""src/Gone.php":"a file that cannot be read""#),
            "{line}"
        );
    }

    #[test]
    fn says_its_contract_stops_and_refuses_anything_else() {
        let mut state = State::default();

        assert_eq!(
            asked(&mut state, "contract"),
            r#"{"outbreak":1,"serving":true}"#
        );
        assert!(answered(&mut state, "stop").stops);

        for line in [
            "other",
            "proofs []",
            r#"proofs {"other": []}"#,
            r#"proofs {"keys": [], "more": 1}"#,
            r#"proofs {"keys": []} x"#,
            "graph {}",
            r#"keep {"proofs": {"k": 1}}"#,
        ] {
            assert!(asked(&mut state, line).contains(r#""refused":"#), "{line}");
        }
    }

    #[test]
    fn reads_a_client_waiting_for_a_change_and_answers_it() {
        assert_eq!(
            waiting(r#"changed {"wait": 2000, "paths": ["src", "tests"], "since": 3}"#),
            Some(Ok(Waiting {
                watched: Watched::Files(vec![String::from("src"), String::from("tests")]),
                since: 3,
                wait: Duration::from_secs(2)
            }))
        );
        assert_eq!(
            waiting(r#"power {"since": 1, "wait": 0}"#),
            Some(Ok(Waiting {
                watched: Watched::Power,
                since: 1,
                wait: Duration::ZERO
            }))
        );
        assert!(matches!(
            waiting(r#"power {"paths": [], "since": 1, "wait": 0}"#),
            Some(Err(_))
        ));
        assert_eq!(waiting("contract"), None);
        assert_eq!(waiting(r#"proofs {"keys": []}"#), None);

        for line in [
            "changed",
            r#"changed {"paths": [], "since": 0}"#,
            r#"changed {"paths": [], "since": -1, "wait": 0}"#,
            r#"changed {"paths": [], "since": 0, "wait": 0.5}"#,
            r#"changed {"paths": [1], "since": 0, "wait": 0}"#,
            r#"changed {"paths": [], "since": 0, "wait": 0, "more": 1}"#,
        ] {
            assert!(matches!(waiting(line), Some(Err(_))), "{line}");
        }

        assert_eq!(changed(4, 1), r#"{"outbreak":1,"changed":4}"#);
        assert_eq!(
            unwatched("no \"inotify\"", 1),
            r#"{"outbreak":1,"unwatched":"no \"inotify\""}"#
        );
    }
}
