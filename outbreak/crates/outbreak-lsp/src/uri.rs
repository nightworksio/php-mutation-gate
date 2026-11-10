//! `file` URIs: the path the editor's workspace root names, and the URI of a file the gate
//! names relative to that root.

use std::fmt::Write;
use std::path::PathBuf;

/// How a `file` URI starts.
const SCHEME: &str = "file://";

/// The host a `file` URI may name besides none.
const LOCAL: &str = "localhost";

/// The path a `file` URI names on this machine; none where it is no `file` URI, names another
/// host, or escapes to bytes that are not UTF-8.
#[must_use]
pub fn path(uri: &str) -> Option<PathBuf> {
    let rest = uri.get(..SCHEME.len()).filter(|scheme| scheme.eq_ignore_ascii_case(SCHEME)).and(uri.get(SCHEME.len()..))?;
    let (host, path) = rest.split_at_checked(rest.find('/')?)?;

    if !host.is_empty() && !host.eq_ignore_ascii_case(LOCAL) {
        return None;
    }

    Some(PathBuf::from(local(decoded(path)?)))
}

/// The URI of a file at a path relative to the root this URI names, separated by `/` or `\`.
#[must_use]
pub fn of(root: &str, relative: &str) -> String {
    let mut uri = root.trim_end_matches('/').to_owned();

    for segment in relative.split(['/', '\\']).filter(|segment| !segment.is_empty()) {
        uri.push('/');
        encode(segment, &mut uri);
    }

    uri
}

fn decoded(path: &str) -> Option<String> {
    let mut bytes = Vec::with_capacity(path.len());
    let mut rest = path.as_bytes();

    while let Some((&byte, after)) = rest.split_first() {
        rest = after;

        if byte != b'%' {
            bytes.push(byte);
            continue;
        }

        let (high, low) = (hex(*rest.first()?)?, hex(*rest.get(1)?)?);
        bytes.push((high << 4) | low);
        rest = rest.get(2..)?;
    }

    String::from_utf8(bytes).ok()
}

fn hex(digit: u8) -> Option<u8> {
    char::from(digit).to_digit(16).and_then(|value| u8::try_from(value).ok())
}

fn encode(segment: &str, uri: &mut String) {
    for byte in segment.bytes() {
        if byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'.' | b'_' | b'~') {
            uri.push(char::from(byte));
        } else {
            let _ = write!(uri, "%{byte:02X}");
        }
    }
}

#[cfg(windows)]
fn local(path: String) -> String {
    let bytes = path.as_bytes();
    let drive = bytes.first() == Some(&b'/') && bytes.get(1).is_some_and(u8::is_ascii_alphabetic) && bytes.get(2) == Some(&b':');

    match path.get(1..) {
        Some(rest) if drive => rest.to_owned(),
        _ => path,
    }
}

#[cfg(not(windows))]
fn local(path: String) -> String {
    path
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_the_path_a_local_file_uri_names() {
        assert_eq!(path("file:///home/me/my%20app"), Some(PathBuf::from("/home/me/my app")));
        assert_eq!(path("FILE://localhost/srv/x"), Some(PathBuf::from("/srv/x")));
        assert_eq!(path("file:///caf%C3%A9"), Some(PathBuf::from("/café")));
    }

    #[test]
    fn reads_no_path_of_another_host_scheme_or_escape() {
        for uri in ["file://server/share", "http:///x", "file:", "file:///%zz", "file:///%c3", "file:///%ff"] {
            assert_eq!(path(uri), None, "{uri}");
        }
    }

    #[test]
    fn writes_the_uri_of_a_file_under_the_root() {
        assert_eq!(of("file:///home/me/app/", "src/My Class.php"), "file:///home/me/app/src/My%20Class.php");
        assert_eq!(of("file:///c%3A/app", "src\\a#b.php"), "file:///c%3A/app/src/a%23b.php");
        assert_eq!(path(&of("file:///app", "src/é.php")), Some(PathBuf::from("/app/src/é.php")));
    }
}
