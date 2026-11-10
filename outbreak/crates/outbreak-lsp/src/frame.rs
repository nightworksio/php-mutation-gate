//! The base protocol's frames: headers, `Content-Length` among them, a blank line, and that
//! many bytes of JSON.

use std::io::{self, BufRead, Read, Write};

/// The most bytes a frame's content may hold.
pub const MOST_CONTENT: usize = 64 * 1024 * 1024;

/// The most bytes one header line may hold, its line break included.
const MOST_HEADER: u64 = 1024;

/// The header that says how long the content is, as its name is written in lower case.
const LENGTH: &str = "content-length:";

/// The content of the next frame; none where the stream ended before another began.
///
/// # Errors
///
/// Fails where the stream breaks or ends inside a frame, a header line is too long or not
/// text, or the frame says no length, or one past [`MOST_CONTENT`].
pub fn read(input: &mut impl BufRead) -> io::Result<Option<Vec<u8>>> {
    let mut length = None;
    let mut line = String::new();
    let mut began = false;

    loop {
        line.clear();

        if input.by_ref().take(MOST_HEADER).read_line(&mut line)? == 0 {
            return if began { Err(refused("the stream ended inside a frame's headers")) } else { Ok(None) };
        }

        began = true;

        let Some(header) = line.strip_suffix('\n') else {
            return Err(refused("a header line is too long"));
        };
        let header = header.strip_suffix('\r').unwrap_or(header);

        if header.is_empty() {
            break;
        }

        if let Some(value) = valued(header) {
            length = Some(value.trim().parse::<usize>().map_err(|_| refused("Content-Length is not a length"))?);
        }
    }

    let length = length.ok_or_else(|| refused("a frame says no Content-Length"))?;

    if length > MOST_CONTENT {
        return Err(refused("a frame's content is too long"));
    }

    let mut content = vec![0; length];
    input.read_exact(&mut content)?;

    Ok(Some(content))
}

/// A frame of this content.
///
/// # Errors
///
/// Fails where the output breaks.
pub fn write(output: &mut impl Write, content: &str) -> io::Result<()> {
    write!(output, "Content-Length: {}\r\n\r\n{content}", content.len())?;
    output.flush()
}

/// The value of a `Content-Length` header; none of any other header.
fn valued(header: &str) -> Option<&str> {
    let name = header.get(..LENGTH.len())?;

    if name.eq_ignore_ascii_case(LENGTH) { header.get(LENGTH.len()..) } else { None }
}

fn refused(why: &str) -> io::Error {
    io::Error::new(io::ErrorKind::InvalidData, why)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn frames(mut bytes: &[u8]) -> Vec<io::Result<Option<Vec<u8>>>> {
        let mut read = Vec::new();

        loop {
            let frame = super::read(&mut bytes);
            let last = !matches!(frame, Ok(Some(_)));
            read.push(frame);

            if last {
                return read;
            }
        }
    }

    #[test]
    fn reads_each_frame_and_ends_where_the_stream_does() {
        let read = frames(b"Content-Length: 2\r\n\r\n{}content-length:4\r\nContent-Type: x\r\n\r\nnull");

        assert_eq!(read.len(), 3);
        assert_eq!(read[0].as_ref().unwrap().as_deref(), Some(&b"{}"[..]));
        assert_eq!(read[1].as_ref().unwrap().as_deref(), Some(&b"null"[..]));
        assert!(matches!(read[2], Ok(None)));
    }

    #[test]
    fn refuses_a_frame_it_cannot_read() {
        for bytes in [
            &b"Content-Type: x\r\n\r\n{}"[..],
            b"Content-Length: two\r\n\r\n{}",
            b"Content-Length: 99999999999\r\n\r\n",
            b"Content-Length: 4\r\n\r\n{}",
            b"Content-Length: 2\r\n",
        ] {
            assert!(frames(bytes)[0].is_err(), "{}", String::from_utf8_lossy(bytes));
        }

        let long = format!("X-Long: {}\r\n\r\n", "x".repeat(2048));

        assert!(frames(long.as_bytes())[0].is_err());
    }

    #[test]
    fn writes_a_frame_it_reads_back() {
        let mut written = Vec::new();
        write(&mut written, r#"{"id":1}"#).unwrap();

        assert_eq!(written, b"Content-Length: 8\r\n\r\n{\"id\":1}");
        assert_eq!(frames(&written)[0].as_ref().unwrap().as_deref(), Some(&b"{\"id\":1}"[..]));
    }
}
