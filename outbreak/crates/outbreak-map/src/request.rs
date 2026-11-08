//! What the gate asks `outbreak map split` for, as JSON on its input:
//! `{"whole": <path>, "slices": [{"into": <path>, "files": [<path>, …]}, …]}`.
//! The whole map is read from `whole`, and each slice written to its `into`,
//! holding the files it lists. Every key is read once, and none other is.

use std::collections::HashSet;

use crate::json::{Cursor, Key, Refused};

/// One slice asked for: where it is written, and the files it holds.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Slice {
    /// Where the slice is written.
    pub into: String,
    /// The paths of the files it holds.
    pub files: HashSet<String>,
}

/// The split asked for: the whole map, and each slice of it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    /// Where the whole map is read from.
    pub whole: String,
    /// Each slice, in the order asked.
    pub slices: Vec<Slice>,
}

/// The request a text holds.
///
/// # Errors
///
/// Refuses a text that is not such a request.
pub fn read(text: &[u8]) -> Result<Request, Refused> {
    let mut json = Cursor::over(text)?;
    let mut members = json.object()?;
    let mut keys = Keys::default();
    let (mut whole, mut slices) = (None, None);

    while let Some(key) = members.next(&mut json)? {
        match keys.once(key)?.as_str() {
            "whole" => whole = Some(json.string()?),
            "slices" => slices = Some(read_slices(&mut json)?),
            _ => return Err(keys.unknown()),
        }
    }

    json.end()?;

    match (whole, slices) {
        (Some(whole), Some(slices)) => Ok(Request { whole, slices }),
        _ => Err(Refused {
            at: 0,
            expected: "a whole map and its slices",
        }),
    }
}

fn read_slices(json: &mut Cursor<'_>) -> Result<Vec<Slice>, Refused> {
    let mut items = json.array()?;
    let mut slices = Vec::new();

    while items.next(json)? {
        let at = json.value_start();
        let mut members = json.object()?;
        let mut keys = Keys::default();
        let (mut into, mut files) = (None, None);

        while let Some(key) = members.next(json)? {
            match keys.once(key)?.as_str() {
                "into" => into = Some(json.string()?),
                "files" => files = Some(read_files(json)?),
                _ => return Err(keys.unknown()),
            }
        }

        match (into, files) {
            (Some(into), Some(files)) => slices.push(Slice { into, files }),
            _ => {
                return Err(Refused {
                    at,
                    expected: "where a slice goes and its files",
                });
            }
        }
    }

    Ok(slices)
}

fn read_files(json: &mut Cursor<'_>) -> Result<HashSet<String>, Refused> {
    let mut items = json.array()?;
    let mut files = HashSet::new();

    while items.next(json)? {
        files.insert(json.string()?);
    }

    Ok(files)
}

/// The keys of one object read so far, and where the last stood.
#[derive(Debug, Default)]
struct Keys {
    seen: HashSet<String>,
    last: usize,
}

impl Keys {
    fn once(&mut self, key: Key) -> Result<String, Refused> {
        self.last = key.raw.start;

        if self.seen.insert(key.text.clone()) {
            Ok(key.text)
        } else {
            Err(Refused {
                at: self.last,
                expected: "each key once",
            })
        }
    }

    fn unknown(&self) -> Refused {
        Refused {
            at: self.last,
            expected: "a key a split request writes",
        }
    }
}

#[cfg(test)]
mod tests {
    use std::collections::HashSet;

    use super::{Request, Slice, read};
    use crate::json::Refused;

    fn refusal(text: &str) -> Refused {
        read(text.as_bytes()).unwrap_err()
    }

    #[test]
    fn reads_the_whole_map_and_each_slice_asked_for() {
        let request = read(
            br#"{"whole":"/w/map.json.gz","slices":[{"into":"/w/1.gz","files":["a","b","a"]},{"files":[],"into":"/w/2.gz"}]}"#,
        )
        .unwrap();

        assert_eq!(
            request,
            Request {
                whole: "/w/map.json.gz".into(),
                slices: vec![
                    Slice {
                        into: "/w/1.gz".into(),
                        files: HashSet::from(["a".to_owned(), "b".to_owned()]),
                    },
                    Slice {
                        into: "/w/2.gz".into(),
                        files: HashSet::new(),
                    },
                ],
            }
        );
    }

    #[test]
    fn refuses_a_request_without_what_it_needs() {
        assert_eq!(
            refusal(r#"{"whole":"w"}"#).expected,
            "a whole map and its slices"
        );
        assert_eq!(
            refusal(r#"{"slices":[]}"#).expected,
            "a whole map and its slices"
        );
        assert_eq!(
            refusal(r#"{"whole":"w","slices":[ {"into":"x"}]}"#),
            Refused {
                at: 24,
                expected: "where a slice goes and its files"
            }
        );
    }

    #[test]
    fn refuses_a_key_written_twice_or_one_no_request_writes() {
        assert_eq!(
            refusal(r#"{"whole":"w","whole":"v","slices":[]}"#),
            Refused {
                at: 13,
                expected: "each key once"
            }
        );
        assert_eq!(
            refusal(r#"{"whole":"w","slices":[],"more":1}"#),
            Refused {
                at: 25,
                expected: "a key a split request writes"
            }
        );
        assert_eq!(
            refusal(r#"{"whole":"w","slices":[{"into":"x","files":[],"x":1}]}"#).expected,
            "a key a split request writes"
        );
        assert_eq!(
            refusal(r#"{"whole":"w","slices":[{"into":"x","into":"y","files":[]}]}"#).expected,
            "each key once"
        );
    }

    #[test]
    fn refuses_a_request_that_is_not_json_or_ends_late() {
        assert_eq!(refusal("[]").expected, "an object");
        assert_eq!(
            refusal(r#"{"whole":"w","slices":[]}x"#).expected,
            "the end of the text"
        );
        assert_eq!(
            refusal(r#"{"whole":"w","slices":[{"into":"x","files":[1]}]}"#).expected,
            "text"
        );
    }
}
