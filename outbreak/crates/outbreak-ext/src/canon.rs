//! A canonical byte text: every value tagged with its kind and every
//! variable-length part led by its length, so two texts are equal only where
//! the values written into them are, in the same order.

use std::fmt::Write as _;

use sha2::{Digest, Sha256};

/// The kind of each value written, so no two kinds read alike.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(u8)]
pub enum Tag {
    /// A section, as the walker names one.
    Section = 1,
    /// An unsigned number.
    Number = 2,
    /// A signed number.
    Signed = 3,
    /// A float, by its bits.
    Float = 4,
    /// Bytes, as they are.
    Bytes = 5,
    /// The end of a list, after its count.
    End = 6,
}

/// The text written so far, and its digest once it is whole.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct Canon {
    bytes: Vec<u8>,
}

impl Canon {
    /// An empty text.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// A section of this name begins.
    pub fn section(&mut self, name: &str) {
        self.tagged(Tag::Section, name.as_bytes());
    }

    /// An unsigned number.
    pub fn number(&mut self, value: u64) {
        self.bytes.push(Tag::Number as u8);
        self.bytes.extend_from_slice(&value.to_le_bytes());
    }

    /// A signed number.
    pub fn signed(&mut self, value: i64) {
        self.bytes.push(Tag::Signed as u8);
        self.bytes.extend_from_slice(&value.to_le_bytes());
    }

    /// A float, by its bits, so `0.0` and `-0.0` and each NaN stay apart.
    pub fn float(&mut self, value: f64) {
        self.bytes.push(Tag::Float as u8);
        self.bytes.extend_from_slice(&value.to_bits().to_le_bytes());
    }

    /// Bytes, as they are.
    pub fn bytes(&mut self, value: &[u8]) {
        self.tagged(Tag::Bytes, value);
    }

    /// A list ends, after this many items.
    pub fn end(&mut self, count: u64) {
        self.bytes.push(Tag::End as u8);
        self.bytes.extend_from_slice(&count.to_le_bytes());
    }

    /// The SHA-256 of the whole text, in lowercase hex.
    #[must_use]
    pub fn digest(&self) -> String {
        Sha256::digest(&self.bytes)
            .iter()
            .fold(String::new(), |mut hex, byte| {
                let _ = write!(hex, "{byte:02x}");
                hex
            })
    }

    fn tagged(&mut self, tag: Tag, value: &[u8]) {
        self.bytes.push(tag as u8);
        self.bytes
            .extend_from_slice(&(value.len() as u64).to_le_bytes());
        self.bytes.extend_from_slice(value);
    }
}

#[cfg(test)]
mod tests {
    use super::Canon;

    fn written(write: impl FnOnce(&mut Canon)) -> Vec<u8> {
        let mut canon = Canon::new();
        write(&mut canon);

        canon.bytes
    }

    #[test]
    fn tags_each_value_and_leads_each_variable_part_by_its_length() {
        assert_eq!(
            written(|canon| canon.section("ab")),
            [1, 2, 0, 0, 0, 0, 0, 0, 0, b'a', b'b']
        );
        assert_eq!(
            written(|canon| canon.number(258)),
            [2, 2, 1, 0, 0, 0, 0, 0, 0]
        );
        assert_eq!(
            written(|canon| canon.signed(-1)),
            [3, 255, 255, 255, 255, 255, 255, 255, 255]
        );
        assert_eq!(
            written(|canon| canon.float(-0.0)),
            [4, 0, 0, 0, 0, 0, 0, 0, 128]
        );
        assert_eq!(
            written(|canon| canon.bytes(b"\0")),
            [5, 1, 0, 0, 0, 0, 0, 0, 0, 0]
        );
        assert_eq!(written(|canon| canon.end(3)), [6, 3, 0, 0, 0, 0, 0, 0, 0]);
    }

    #[test]
    fn reads_no_two_kinds_alike() {
        assert_ne!(
            written(|canon| canon.section("a")),
            written(|canon| canon.bytes(b"a"))
        );
        assert_ne!(
            written(|canon| canon.float(0.0)),
            written(|canon| canon.float(-0.0))
        );
    }

    #[test]
    fn digests_the_whole_text_as_sha256_in_lowercase_hex() {
        assert_eq!(
            Canon::new().digest(),
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
        );

        let mut canon = Canon::new();
        canon.section("return");

        assert_eq!(canon.digest().len(), 64);
        assert_ne!(canon.digest(), Canon::new().digest());
    }
}
