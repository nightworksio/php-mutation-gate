//! The JSON-RPC messages a client sends, read as far as the server needs them, and the
//! messages it answers with.

use outbreak_graph::quoted;
use outbreak_map::json::Cursor;

/// The `jsonrpc` member every message carries.
const VERSION: &str = r#""jsonrpc":"2.0""#;

/// One message from the client: a request where it has an id and a method, a notification
/// where it has a method alone, and an answer to the server where it has no method.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Message<'a> {
    /// The request's id, as the client wrote it.
    pub id: Option<&'a str>,
    /// What it asks.
    pub method: Option<String>,
    /// Its parameters, as the client wrote them.
    pub params: Option<&'a [u8]>,
}

/// Why the server answers a request with an error, by the code JSON-RPC and the protocol give it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(i32)]
pub enum Fault {
    /// The message is not JSON-RPC.
    Unreadable = -32700,
    /// The request comes once the server was initialized, or asked to shut down.
    Invalid = -32600,
    /// The server answers no such method.
    Unknown = -32601,
    /// The request's parameters are not ones the method takes.
    Params = -32602,
    /// The request comes before the server was initialized.
    Uninitialized = -32002,
}

impl Fault {
    /// What the error says.
    #[must_use]
    pub const fn said(self) -> &'static str {
        match self {
            Self::Unreadable => "the message is not JSON-RPC",
            Self::Invalid => "the server takes no such request now",
            Self::Unknown => "the server answers no such method",
            Self::Params => "the parameters are not ones the method takes",
            Self::Uninitialized => "the server is not initialized yet",
        }
    }
}

/// The message a frame's content is; none where it is not a JSON object.
#[must_use]
pub fn read(content: &[u8]) -> Option<Message<'_>> {
    let mut cursor = Cursor::over(content).ok()?;
    let mut members = cursor.object().ok()?;
    let mut message = Message {
        id: None,
        method: None,
        params: None,
    };

    while let Some(key) = members.next(&mut cursor).ok()? {
        match key.text.as_str() {
            "id" => message.id = Some(std::str::from_utf8(raw(&mut cursor)?).ok()?),
            "method" => message.method = Some(cursor.string().ok()?),
            "params" => message.params = Some(raw(&mut cursor)?),
            _ => cursor.skip().ok()?,
        }
    }

    cursor.end().ok()?;

    Some(message)
}

/// The value of an object's member, as it is written; none where the JSON is no object or
/// holds no such member.
#[must_use]
pub fn member<'a>(json: &'a [u8], name: &str) -> Option<&'a [u8]> {
    let mut cursor = Cursor::over(json).ok()?;
    let mut members = cursor.object().ok()?;

    while let Some(key) = members.next(&mut cursor).ok()? {
        if key.text == name {
            return raw(&mut cursor);
        }

        cursor.skip().ok()?;
    }

    None
}

/// The value at a path of members, as [`member`] reads each.
#[must_use]
pub fn at<'a>(json: &'a [u8], path: &[&str]) -> Option<&'a [u8]> {
    path.iter()
        .try_fold(json, |value, name| member(value, name))
}

/// The string the JSON is; none where it is anything else.
#[must_use]
pub fn string(json: &[u8]) -> Option<String> {
    let mut cursor = Cursor::over(json).ok()?;
    let text = cursor.string().ok()?;
    cursor.end().ok()?;

    Some(text)
}

/// The boolean the JSON is; none where it is anything else.
#[must_use]
pub fn boolean(json: &[u8]) -> Option<bool> {
    let mut cursor = Cursor::over(json).ok()?;
    let boolean = cursor.boolean().ok()?;
    cursor.end().ok()?;

    Some(boolean)
}

/// The items of the array the JSON is, each as it is written; none where it is anything else.
#[must_use]
pub fn items(json: &[u8]) -> Option<Vec<&[u8]>> {
    let mut cursor = Cursor::over(json).ok()?;
    let mut items = cursor.array().ok()?;
    let mut read = Vec::new();

    while items.next(&mut cursor).ok()? {
        read.push(raw(&mut cursor)?);
    }

    cursor.end().ok()?;

    Some(read)
}

/// The answer to a request: its result, written as JSON.
#[must_use]
pub fn result(id: &str, result: &str) -> String {
    format!(r#"{{{VERSION},"id":{id},"result":{result}}}"#)
}

/// The answer to a request the server refuses.
#[must_use]
pub fn error(id: &str, fault: Fault) -> String {
    format!(
        r#"{{{VERSION},"id":{id},"error":{{"code":{},"message":{}}}}}"#,
        fault as i32,
        quoted(fault.said())
    )
}

/// A notification the server sends: its method and its parameters, written as JSON.
#[must_use]
pub fn notification(method: &str, params: &str) -> String {
    format!(
        r#"{{{VERSION},"method":{},"params":{params}}}"#,
        quoted(method)
    )
}

fn raw<'a>(cursor: &mut Cursor<'a>) -> Option<&'a [u8]> {
    let span = cursor.spanned().ok()?;

    Some(cursor.bytes(span))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_a_request_a_notification_and_an_answer() {
        let request =
            read(br#"{"jsonrpc":"2.0","id":7,"method":"initialize","params":{"rootUri":null}}"#)
                .unwrap();

        assert_eq!(request.id, Some("7"));
        assert_eq!(request.method.as_deref(), Some("initialize"));
        assert_eq!(request.params, Some(&br#"{"rootUri":null}"#[..]));

        let notification = read(br#"{"jsonrpc":"2.0","method":"exit"}"#).unwrap();

        assert_eq!(
            (notification.id, notification.method.as_deref()),
            (None, Some("exit"))
        );
        assert_eq!(
            read(br#"{"id":"a","result":null}"#).unwrap().id,
            Some(r#""a""#)
        );
    }

    #[test]
    fn reads_no_message_from_anything_but_one_object() {
        for content in [&b"[]"[..], b"{", b"{} {}", b"{\"method\":1}", b"\xff"] {
            assert_eq!(read(content), None, "{}", String::from_utf8_lossy(content));
        }
    }

    #[test]
    fn reads_members_at_a_path_strings_numbers_and_items() {
        let json = br#"{"a":{"b":["x",{"c":"y"}],"n":3,"f":1.5}}"#;

        assert_eq!(at(json, &["a", "b"]).and_then(items).unwrap().len(), 2);
        assert_eq!(at(json, &["a", "n"]).and_then(boolean), None);
        assert_eq!(at(br#"{"q":true}"#, &["q"]).and_then(boolean), Some(true));
        assert_eq!(
            at(json, &["a", "b"])
                .and_then(items)
                .and_then(|read| read.first().copied())
                .and_then(string)
                .as_deref(),
            Some("x")
        );
        assert_eq!(at(json, &["a", "z"]), None);
        assert_eq!(at(br#""text""#, &["a"]), None);
        assert_eq!(string(b"null"), None);
    }

    #[test]
    fn writes_results_errors_and_notifications() {
        assert_eq!(
            result("1", "null"),
            r#"{"jsonrpc":"2.0","id":1,"result":null}"#
        );
        assert_eq!(
            error(r#""x""#, Fault::Unknown),
            r#"{"jsonrpc":"2.0","id":"x","error":{"code":-32601,"message":"the server answers no such method"}}"#
        );
        assert_eq!(
            notification("exit", "{}"),
            r#"{"jsonrpc":"2.0","method":"exit","params":{}}"#
        );
    }
}
