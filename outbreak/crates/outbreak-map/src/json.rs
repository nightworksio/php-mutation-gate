//! A strict reader of JSON text, one token at a time, as RFC 8259 writes it:
//! UTF-8 only, no byte order mark, no lone surrogate, and nothing after the
//! one value. It keeps where each value stands, so a caller can copy a value's
//! bytes as they are, and whether any whitespace stood between tokens.

use std::ops::Range;

/// Why a text is refused: where in it, and what was expected there.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Refused {
    /// The byte the refusal stands at.
    pub at: usize,
    /// What the text should have held there.
    pub expected: &'static str,
}

/// How deep a value the reader skips may nest.
pub const DEEPEST: usize = 64;

/// A number as the reader found it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Number {
    /// Digits alone, with no sign, fraction or exponent, that fit in a `u64`.
    Whole(u64),
    /// Any other number: signed, with a fraction or an exponent, or too large.
    Other {
        /// Whether it is written with a leading minus.
        negative: bool,
    },
}

/// A cursor over one JSON text.
#[derive(Debug)]
pub struct Cursor<'a> {
    text: &'a [u8],
    at: usize,
    spaced: bool,
}

/// The members of an object being read: each key in turn, the cursor left at its value.
#[derive(Debug)]
pub struct Members {
    first: bool,
}

/// The items of an array being read: each in turn, the cursor left at it.
#[derive(Debug)]
pub struct Items {
    first: bool,
}

/// One key of an object: its text, and the bytes it was written in, quotes included.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Key {
    /// The key, its escapes read.
    pub text: String,
    /// Where the key is written, quotes included.
    pub raw: Range<usize>,
}

impl<'a> Cursor<'a> {
    /// A cursor at the start of a text, refused where it is not UTF-8.
    ///
    /// # Errors
    ///
    /// Refuses a text that is not UTF-8, at the first byte that is not.
    pub fn over(text: &'a [u8]) -> Result<Self, Refused> {
        std::str::from_utf8(text).map_err(|error| Refused {
            at: error.valid_up_to(),
            expected: "UTF-8",
        })?;

        Ok(Self {
            text,
            at: 0,
            spaced: false,
        })
    }

    /// Where the cursor stands.
    #[must_use]
    pub fn at(&self) -> usize {
        self.at
    }

    /// Whether whitespace stood between any two tokens read so far.
    #[must_use]
    pub fn spaced(&self) -> bool {
        self.spaced
    }

    /// The bytes of the text in a range, or none where it reaches past the end.
    #[must_use]
    pub fn bytes(&self, range: Range<usize>) -> &'a [u8] {
        self.text.get(range).unwrap_or_default()
    }

    /// That nothing but whitespace is left.
    ///
    /// # Errors
    ///
    /// Refuses a text with anything after its value.
    pub fn end(&mut self) -> Result<(), Refused> {
        self.space();

        match self.peek() {
            None => Ok(()),
            Some(_) => Err(self.refused("the end of the text")),
        }
    }

    /// The start of an object.
    ///
    /// # Errors
    ///
    /// Refuses anything but `{`.
    pub fn object(&mut self) -> Result<Members, Refused> {
        self.space();
        self.byte(b'{', "an object")?;

        Ok(Members { first: true })
    }

    /// The start of an array.
    ///
    /// # Errors
    ///
    /// Refuses anything but `[`.
    pub fn array(&mut self) -> Result<Items, Refused> {
        self.space();
        self.byte(b'[', "a list")?;

        Ok(Items { first: true })
    }

    /// Where the next value starts, the whitespace before it read.
    pub fn value_start(&mut self) -> usize {
        self.space();

        self.at
    }

    /// A string, its escapes read.
    ///
    /// # Errors
    ///
    /// Refuses anything but a well-formed string.
    pub fn string(&mut self) -> Result<String, Refused> {
        self.space();
        self.byte(b'"', "text")?;
        let mut text = Vec::new();

        loop {
            let byte = self
                .next()
                .ok_or_else(|| self.refused("the end of the text"))?;

            match byte {
                b'"' => break,
                b'\\' => self.escape(&mut text)?,
                0x00..=0x1f => return Err(self.refused_before("no control character")),
                other => text.push(other),
            }
        }

        Ok(String::from_utf8_lossy(&text).into_owned())
    }

    /// A number.
    ///
    /// # Errors
    ///
    /// Refuses anything but a number as JSON writes one.
    pub fn number(&mut self) -> Result<Number, Refused> {
        self.space();
        let negative = self.peek() == Some(b'-');

        if negative {
            self.at += 1;
        }

        let whole = self.digits()?;
        let fraction = self.fraction()?;
        let exponent = self.exponent()?;

        Ok(match whole {
            Some(value) if !negative && !fraction && !exponent => Number::Whole(value),
            _ => Number::Other { negative },
        })
    }

    /// `true` or `false`.
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    pub fn boolean(&mut self) -> Result<bool, Refused> {
        self.space();

        match self.peek() {
            Some(b't') => self.word(b"true").map(|()| true),
            Some(b'f') => self.word(b"false").map(|()| false),
            _ => Err(self.refused("true or false")),
        }
    }

    /// A list of whole numbers, each digits alone, and the largest of them; none where it is
    /// empty.
    ///
    /// # Errors
    ///
    /// Refuses anything but such a list.
    pub fn wholes(&mut self) -> Result<Option<u64>, Refused> {
        let mut items = self.array()?;
        let mut largest = None;

        while items.next(self)? {
            self.space();
            let start = self.at;

            match self.number()? {
                Number::Whole(value) => largest = largest.max(Some(value)),
                Number::Other { .. } => {
                    return Err(Refused {
                        at: start,
                        expected: "a whole number",
                    });
                }
            }
        }

        Ok(largest)
    }

    /// Any value, read for its form alone, nested no deeper than [`DEEPEST`].
    ///
    /// # Errors
    ///
    /// Refuses a value that is not well formed, or nests deeper.
    pub fn skip(&mut self) -> Result<(), Refused> {
        self.skip_within(DEEPEST)
    }

    /// The range a value stands in, read for its form alone.
    ///
    /// # Errors
    ///
    /// Refuses as [`Cursor::skip`] does.
    pub fn spanned(&mut self) -> Result<Range<usize>, Refused> {
        self.space();
        let start = self.at;
        self.skip()?;

        Ok(start..self.at)
    }

    fn skip_within(&mut self, depth: usize) -> Result<(), Refused> {
        self.space();
        let deeper = depth
            .checked_sub(1)
            .ok_or_else(|| self.refused("a value nested less deep"))?;

        match self.peek() {
            Some(b'{') => {
                let mut members = self.object()?;

                while members.next(self)?.is_some() {
                    self.skip_within(deeper)?;
                }

                Ok(())
            }
            Some(b'[') => {
                let mut items = self.array()?;

                while items.next(self)? {
                    self.skip_within(deeper)?;
                }

                Ok(())
            }
            Some(b'"') => self.string().map(drop),
            Some(b't') => self.word(b"true"),
            Some(b'f') => self.word(b"false"),
            Some(b'n') => self.word(b"null"),
            _ => self.number().map(drop),
        }
    }

    fn word(&mut self, word: &[u8]) -> Result<(), Refused> {
        let end = self.at + word.len();

        if self.text.get(self.at..end) == Some(word) {
            self.at = end;
            Ok(())
        } else {
            Err(self.refused("a value"))
        }
    }

    fn digits(&mut self) -> Result<Option<u64>, Refused> {
        match self.peek() {
            Some(b'0') => {
                self.at += 1;
                Ok(Some(0))
            }
            Some(b'1'..=b'9') => {
                let mut value = Some(0_u64);

                while let Some(digit @ b'0'..=b'9') = self.peek() {
                    value = value
                        .and_then(|value| value.checked_mul(10))
                        .and_then(|value| value.checked_add(u64::from(digit - b'0')));
                    self.at += 1;
                }

                Ok(value)
            }
            _ => Err(self.refused("a number")),
        }
    }

    fn fraction(&mut self) -> Result<bool, Refused> {
        if self.peek() != Some(b'.') {
            return Ok(false);
        }

        self.at += 1;
        self.some_digits()?;

        Ok(true)
    }

    fn exponent(&mut self) -> Result<bool, Refused> {
        if !matches!(self.peek(), Some(b'e' | b'E')) {
            return Ok(false);
        }

        self.at += 1;

        if matches!(self.peek(), Some(b'+' | b'-')) {
            self.at += 1;
        }

        self.some_digits()?;

        Ok(true)
    }

    fn some_digits(&mut self) -> Result<(), Refused> {
        let start = self.at;

        while matches!(self.peek(), Some(b'0'..=b'9')) {
            self.at += 1;
        }

        if self.at == start {
            Err(self.refused("a digit"))
        } else {
            Ok(())
        }
    }

    fn escape(&mut self, text: &mut Vec<u8>) -> Result<(), Refused> {
        let byte = self.next().ok_or_else(|| self.refused("an escape"))?;
        let plain = match byte {
            b'"' => b'"',
            b'\\' => b'\\',
            b'/' => b'/',
            b'b' => 0x08,
            b'f' => 0x0c,
            b'n' => b'\n',
            b'r' => b'\r',
            b't' => b'\t',
            b'u' => return self.unicode(text),
            _ => return Err(self.refused_before("an escape")),
        };
        text.push(plain);

        Ok(())
    }

    fn unicode(&mut self, text: &mut Vec<u8>) -> Result<(), Refused> {
        let first = self.hex()?;
        let point = match first {
            0xd800..=0xdbff => {
                self.word(b"\\u")
                    .map_err(|_| self.refused("the second half of a surrogate pair"))?;
                let second = self.hex()?;

                if !(0xdc00..=0xdfff).contains(&second) {
                    return Err(self.refused("the second half of a surrogate pair"));
                }

                0x10000 + ((first - 0xd800) << 10) + (second - 0xdc00)
            }
            0xdc00..=0xdfff => return Err(self.refused("no lone surrogate")),
            other => other,
        };
        let character = char::from_u32(point).unwrap_or(char::REPLACEMENT_CHARACTER);
        let mut buffer = [0; 4];
        text.extend_from_slice(character.encode_utf8(&mut buffer).as_bytes());

        Ok(())
    }

    fn hex(&mut self) -> Result<u32, Refused> {
        let digits = self
            .text
            .get(self.at..self.at + 4)
            .ok_or_else(|| self.refused("four hex digits"))?;
        let mut value = 0;

        for digit in digits {
            let nibble = char::from(*digit)
                .to_digit(16)
                .ok_or_else(|| self.refused("four hex digits"))?;
            value = value * 16 + nibble;
        }

        self.at += 4;

        Ok(value)
    }

    fn byte(&mut self, byte: u8, expected: &'static str) -> Result<(), Refused> {
        if self.peek() == Some(byte) {
            self.at += 1;
            Ok(())
        } else {
            Err(self.refused(expected))
        }
    }

    fn space(&mut self) {
        while matches!(self.peek(), Some(b' ' | b'\t' | b'\n' | b'\r')) {
            self.spaced = true;
            self.at += 1;
        }
    }

    fn peek(&self) -> Option<u8> {
        self.text.get(self.at).copied()
    }

    fn next(&mut self) -> Option<u8> {
        let byte = self.peek();
        self.at += usize::from(byte.is_some());

        byte
    }

    fn refused(&self, expected: &'static str) -> Refused {
        Refused {
            at: self.at,
            expected,
        }
    }

    fn refused_before(&self, expected: &'static str) -> Refused {
        Refused {
            at: self.at.saturating_sub(1),
            expected,
        }
    }
}

impl Members {
    /// The next key, the cursor left at its value; none where the object ends.
    ///
    /// # Errors
    ///
    /// Refuses an object that is not well formed.
    pub fn next(&mut self, cursor: &mut Cursor<'_>) -> Result<Option<Key>, Refused> {
        cursor.space();

        if cursor.peek() == Some(b'}') {
            cursor.at += 1;
            return Ok(None);
        }

        if !self.first {
            cursor.byte(b',', "a comma or the object's end")?;
        }

        self.first = false;
        cursor.space();
        let start = cursor.at;
        let text = cursor.string()?;
        let raw = start..cursor.at;
        cursor.space();
        cursor.byte(b':', "a colon")?;

        Ok(Some(Key { text, raw }))
    }
}

impl Items {
    /// Whether another item follows, the cursor left at it.
    ///
    /// # Errors
    ///
    /// Refuses an array that is not well formed.
    pub fn next(&mut self, cursor: &mut Cursor<'_>) -> Result<bool, Refused> {
        cursor.space();

        if cursor.peek() == Some(b']') {
            cursor.at += 1;
            return Ok(false);
        }

        if !self.first {
            cursor.byte(b',', "a comma or the list's end")?;
        }

        self.first = false;

        Ok(true)
    }
}

#[cfg(test)]
mod tests {
    use super::{Cursor, DEEPEST, Key, Number, Refused};

    fn cursor(text: &str) -> Cursor<'_> {
        Cursor::over(text.as_bytes()).unwrap()
    }

    fn refusal(at: usize, expected: &'static str) -> Refused {
        Refused { at, expected }
    }

    #[test]
    fn refuses_a_text_that_is_not_utf8_where_it_stops_being() {
        assert_eq!(Cursor::over(b"\"a\xff\"").unwrap_err(), refusal(2, "UTF-8"));
    }

    #[test]
    fn reads_an_object_key_by_key_with_where_each_key_is_written() {
        let mut json = cursor("{\"a\":1,\"b\":[]}");
        let mut members = json.object().unwrap();

        assert_eq!(
            members.next(&mut json).unwrap(),
            Some(Key {
                text: "a".into(),
                raw: 1..4
            })
        );
        assert_eq!(json.number().unwrap(), Number::Whole(1));
        assert_eq!(members.next(&mut json).unwrap().unwrap().text, "b");
        json.skip().unwrap();
        assert_eq!(members.next(&mut json).unwrap(), None);
        json.end().unwrap();
        assert!(!json.spaced());
        assert_eq!(json.at(), 14);
    }

    #[test]
    fn says_whitespace_stood_between_tokens() {
        let mut json = cursor(" {\t\"a\" :\n1\r} ");
        let mut members = json.object().unwrap();

        members.next(&mut json).unwrap();
        json.number().unwrap();
        assert_eq!(members.next(&mut json).unwrap(), None);
        json.end().unwrap();
        assert!(json.spaced());
    }

    #[test]
    fn refuses_what_follows_the_value() {
        let mut json = cursor("{} x");

        json.skip().unwrap();
        assert_eq!(json.end().unwrap_err(), refusal(3, "the end of the text"));
    }

    #[test]
    fn reads_true_or_false_alone() {
        assert_eq!(cursor(" true").boolean(), Ok(true));
        assert_eq!(cursor("false").boolean(), Ok(false));
        assert_eq!(cursor("1").boolean(), Err(refusal(0, "true or false")));
        assert_eq!(cursor("tree").boolean(), Err(refusal(0, "a value")));
    }

    #[test]
    fn refuses_an_object_or_list_that_is_not_one() {
        assert_eq!(cursor("[").object().unwrap_err(), refusal(0, "an object"));
        assert_eq!(cursor("{").array().unwrap_err(), refusal(0, "a list"));
    }

    #[test]
    fn refuses_a_member_without_its_comma_or_colon() {
        let mut json = cursor("{\"a\":1 \"b\":2}");
        let mut members = json.object().unwrap();
        members.next(&mut json).unwrap();
        json.number().unwrap();
        assert_eq!(
            members.next(&mut json).unwrap_err(),
            refusal(7, "a comma or the object's end")
        );

        let mut json = cursor("{\"a\" 1}");
        let mut members = json.object().unwrap();
        assert_eq!(members.next(&mut json).unwrap_err(), refusal(5, "a colon"));
    }

    #[test]
    fn refuses_an_item_without_its_comma() {
        let mut json = cursor("[1 2]");
        let mut items = json.array().unwrap();

        assert!(items.next(&mut json).unwrap());
        json.number().unwrap();
        assert_eq!(
            items.next(&mut json).unwrap_err(),
            refusal(3, "a comma or the list's end")
        );
    }

    #[test]
    fn finds_where_the_next_value_starts() {
        let mut json = cursor(" \n{}");

        assert_eq!(json.value_start(), 2);
        assert!(json.spaced());
    }

    #[test]
    fn reads_every_escape_a_string_can_hold() {
        let mut json = cursor(r#""\"\\\/\b\f\n\r\t\u00e9\ud83d\ude00é""#);

        assert_eq!(
            json.string().unwrap(),
            "\"\\/\u{8}\u{c}\n\r\t\u{e9}\u{1f600}\u{e9}"
        );
    }

    #[test]
    fn refuses_a_string_that_is_not_well_formed() {
        assert_eq!(cursor("1").string().unwrap_err(), refusal(0, "text"));
        assert_eq!(
            cursor("\"abc").string().unwrap_err(),
            refusal(4, "the end of the text")
        );
        assert_eq!(
            cursor("\"a\u{1}\"").string().unwrap_err(),
            refusal(2, "no control character")
        );
        assert_eq!(
            cursor(r#""\x""#).string().unwrap_err(),
            refusal(2, "an escape")
        );
        assert_eq!(
            cursor("\"\\").string().unwrap_err(),
            refusal(2, "an escape")
        );
        assert_eq!(
            cursor(r#""\u12""#).string().unwrap_err(),
            refusal(3, "four hex digits")
        );
        assert_eq!(
            cursor(r#""\u12g4""#).string().unwrap_err(),
            refusal(3, "four hex digits")
        );
    }

    #[test]
    fn refuses_a_lone_or_broken_surrogate() {
        assert_eq!(
            cursor(r#""\udc00""#).string().unwrap_err(),
            refusal(7, "no lone surrogate")
        );
        assert_eq!(
            cursor(r#""\ud800x""#).string().unwrap_err(),
            refusal(7, "the second half of a surrogate pair")
        );
        assert_eq!(
            cursor("\"\\ud800\\u0041\"").string().unwrap_err(),
            refusal(13, "the second half of a surrogate pair")
        );
    }

    #[test]
    fn reads_a_number_as_whole_only_where_digits_alone_write_it() {
        assert_eq!(cursor("0").number().unwrap(), Number::Whole(0));
        assert_eq!(cursor("120").number().unwrap(), Number::Whole(120));
        assert_eq!(
            cursor("-1").number().unwrap(),
            Number::Other { negative: true }
        );
        assert_eq!(
            cursor("1.5").number().unwrap(),
            Number::Other { negative: false }
        );
        assert_eq!(
            cursor("1e3").number().unwrap(),
            Number::Other { negative: false }
        );
        assert_eq!(
            cursor("1E+3").number().unwrap(),
            Number::Other { negative: false }
        );
        assert_eq!(
            cursor("2e-3").number().unwrap(),
            Number::Other { negative: false }
        );
        assert_eq!(
            cursor("18446744073709551616").number().unwrap(),
            Number::Other { negative: false }
        );
        assert_eq!(
            cursor("18446744073709551615").number().unwrap(),
            Number::Whole(u64::MAX)
        );
    }

    #[test]
    fn refuses_a_number_that_is_not_well_formed() {
        assert_eq!(cursor("-").number().unwrap_err(), refusal(1, "a number"));
        assert_eq!(cursor("1.").number().unwrap_err(), refusal(2, "a digit"));
        assert_eq!(cursor("1e").number().unwrap_err(), refusal(2, "a digit"));
        assert_eq!(cursor("x").number().unwrap_err(), refusal(0, "a number"));
    }

    #[test]
    fn reads_a_list_of_whole_numbers_with_its_largest() {
        assert_eq!(cursor("[3,9,1]").wholes().unwrap(), Some(9));
        assert_eq!(cursor("[]").wholes().unwrap(), None);
        assert_eq!(
            cursor("[1,-1]").wholes().unwrap_err(),
            refusal(3, "a whole number")
        );
    }

    #[test]
    fn skips_any_value_and_spans_it() {
        let mut json = cursor(r#" {"a":[true,false,null,"x",-1.5e2,{}]}"#);

        assert_eq!(json.spanned().unwrap(), 1..38);
        assert_eq!(json.bytes(1..4), b"{\"a");
        assert_eq!(json.bytes(30..99), b"");
    }

    #[test]
    fn refuses_a_word_that_is_not_one() {
        assert_eq!(cursor("tree").skip().unwrap_err(), refusal(0, "a value"));
        assert_eq!(cursor("nul").skip().unwrap_err(), refusal(0, "a value"));
        assert_eq!(cursor("fold").skip().unwrap_err(), refusal(0, "a value"));
    }

    #[test]
    fn refuses_a_value_nested_past_the_deepest() {
        let deep = format!("{}{}", "[".repeat(DEEPEST), "]".repeat(DEEPEST));
        let deeper = format!("{}{}", "[".repeat(DEEPEST + 1), "]".repeat(DEEPEST + 1));

        cursor(&deep).skip().unwrap();
        assert_eq!(
            cursor(&deeper).skip().unwrap_err(),
            refusal(DEEPEST, "a value nested less deep")
        );
    }

    #[test]
    fn refuses_a_skipped_object_or_list_whose_members_are_not_well_formed() {
        assert_eq!(
            cursor("{\"a\":}").skip().unwrap_err(),
            refusal(5, "a number")
        );
        assert_eq!(cursor("[1,]").skip().unwrap_err(), refusal(3, "a number"));
    }
}
