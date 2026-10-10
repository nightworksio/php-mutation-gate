//! Why the helper answers nothing, which sends the gate back to its own PHP.

/// Why a request was refused, in words the gate passes on.
#[derive(Debug, PartialEq, Eq)]
pub struct Refusal {
    why: String,
}

impl Refusal {
    /// A refusal for this reason.
    #[must_use]
    pub fn because(why: &str) -> Self {
        Self { why: why.to_owned() }
    }

    /// The reason.
    #[must_use]
    pub fn why(&self) -> &str {
        &self.why
    }
}
