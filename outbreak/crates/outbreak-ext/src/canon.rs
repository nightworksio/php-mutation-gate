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
