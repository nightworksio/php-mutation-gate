//! The requests the gate sends and the answers it reads: one JSON value in, one JSON value out.

use crate::entry_keys;
use crate::refusal::Refusal;
use crate::unit_keys;
use serde_json::{Value, json};

/// The protocol both sides must name for a request to be answered. It changes whenever a request or answer does.
pub const PROTOCOL: u64 = 1;

/// What the helper says of itself, which the gate compares with what it expects before asking anything.
#[must_use]
pub fn handshake() -> Value {
    json!({
        "name": env!("CARGO_PKG_NAME"),
        "version": env!("CARGO_PKG_VERSION"),
        "protocol": PROTOCOL,
    })
}

/// The answer to a request, worked out on up to this many threads.
///
/// # Errors
///
/// A [`Refusal`] where the request is not JSON, names another protocol or kind, or cannot be answered exactly.
pub fn answer(request: &str, threads: usize) -> Result<Value, Refusal> {
    let request: Value = serde_json::from_str(request)
        .map_err(|error| Refusal::because(&format!("the request is not JSON: {error}")))?;

    if request.get("protocol").and_then(Value::as_u64) != Some(PROTOCOL) {
        return Err(Refusal::because(&format!(
            "the request does not name protocol {PROTOCOL}"
        )));
    }

    match request.get("kind").and_then(Value::as_str) {
        Some("entry-keys") => {
            let keys = entry_keys::Asked::read(&request)?.keys(threads)?;

            Ok(json!({ "protocol": PROTOCOL, "keys": keys }))
        }
        Some("unit-keys") => {
            let keys = unit_keys::Asked::read(&request)?.keys(threads)?;

            Ok(json!({ "protocol": PROTOCOL, "keys": keys }))
        }
        _ => Err(Refusal::because("the request names no kind this helper answers")),
    }
}

#[cfg(test)]
mod tests {
    use super::{PROTOCOL, answer, handshake};
    use serde_json::json;

    #[test]
    fn the_handshake_names_the_helper_its_version_and_the_protocol() {
        let said = handshake();

        assert_eq!(said.get("name"), Some(&json!("mutation-gate-turbo")));
        assert_eq!(said.get("version"), Some(&json!(env!("CARGO_PKG_VERSION"))));
        assert_eq!(said.get("protocol"), Some(&json!(PROTOCOL)));
    }

    #[test]
    fn a_request_that_is_not_json_another_protocol_or_an_unknown_kind_is_refused() {
        assert!(answer("{", 1).is_err());
        assert!(answer(&json!({"protocol": 2, "kind": "entry-keys"}).to_string(), 1).is_err());
        assert!(answer(&json!({"protocol": 1, "kind": "line-keys"}).to_string(), 1).is_err());
        assert!(answer(&json!({"protocol": 1, "kind": "unit-keys"}).to_string(), 1).is_err());
        assert!(answer(&json!({"protocol": 1}).to_string(), 1).is_err());
    }

    #[test]
    fn unit_keys_are_answered_with_the_protocol() {
        let request = json!({
            "protocol": 1, "kind": "unit-keys", "format": "f", "base": "b", "tests": ["t"], "sets": [[0]],
            "units": [{"path": "p", "judgedBy": "", "read": "r", "lines": [[1, 0]]}],
        });

        let answered = answer(&request.to_string(), 2).map_err(|refusal| refusal.why().to_owned());

        assert_eq!(
            answered.map(|value| value
                .get("keys")
                .and_then(serde_json::Value::as_array)
                .map_or(0, Vec::len)),
            Ok(1)
        );
    }

    #[test]
    fn entry_keys_are_answered_with_the_protocol() {
        let request = json!({
            "protocol": 1, "kind": "entry-keys", "format": "f", "base": "b",
            "paths": ["a"], "digests": ["d"], "edges": [[]], "always": [], "entries": [[0], []],
        });

        let answered = answer(&request.to_string(), 2).map_err(|refusal| refusal.why().to_owned());

        assert_eq!(
            answered.as_ref().map(|value| value.get("protocol").cloned()),
            Ok(Some(json!(1)))
        );
        assert_eq!(
            answered.map(|value| value
                .get("keys")
                .and_then(serde_json::Value::as_array)
                .map_or(0, Vec::len)),
            Ok(2)
        );
    }
}
