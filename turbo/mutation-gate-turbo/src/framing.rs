//! Fields as the gate's keys read them: each written with its length in bytes before it.

use sha2::{Digest, Sha256};

/// Writes one field into a hash as `ContentKeys::framed()` does: its length in bytes, a colon, the field and a newline.
pub fn framed(hash: &mut Sha256, field: &[u8]) {
    hash.update(field.len().to_string().as_bytes());
    hash.update(b":");
    hash.update(field);
    hash.update(b"\n");
}

/// The lowercase hex of a finished hash, as PHP's `hash_final()` writes it.
#[must_use]
pub fn hex(hash: Sha256) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let bytes = hash.finalize();
    let mut text = String::with_capacity(bytes.len() * 2);

    for byte in bytes {
        for nibble in [byte >> 4, byte & 0x0f] {
            text.push(char::from(DIGITS.get(usize::from(nibble)).copied().unwrap_or(b'0')));
        }
    }

    text
}

#[cfg(test)]
mod tests {
    use super::{framed, hex};
    use sha2::{Digest, Sha256};

    #[test]
    fn a_field_is_written_with_its_length_in_bytes_and_a_newline() {
        let mut framing = Sha256::new();
        framed(&mut framing, "é1".as_bytes());
        framed(&mut framing, b"");

        let mut plain = Sha256::new();
        plain.update("3:é1\n0:\n".as_bytes());

        assert_eq!(hex(framing), hex(plain));
    }

    #[test]
    fn a_digest_is_written_as_lowercase_hex() {
        assert_eq!(
            hex(Sha256::new()),
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
        );
    }
}
