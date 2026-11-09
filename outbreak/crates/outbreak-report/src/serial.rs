//! A strict reader of the text PHP's `serialize()` writes, for the values a
//! php-code-coverage PHP report holds: null, booleans, integers, floats, byte
//! strings, arrays, and objects of a named class. It refuses references,
//! custom serializations, enums, escaped strings, infinities and NaN, and any
//! value nested deeper than [`DEEPEST`]: anything PHP might read otherwise,
//! or run code to read.

use outbreak_map::json::Refused;

/// How deep a value the reader reads or skips may nest.
pub const DEEPEST: usize = 64;

/// The key of an array's entry, or the name of an object's property.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Key<'a> {
    /// An integer key, `i:<n>;`.
    Integer(i64),
    /// A string key, `s:<n>:"<bytes>";`.
    Text(&'a [u8]),
}

/// A cursor over one serialized value.
#[derive(Debug)]
pub struct Cursor<'a> {
    text: &'a [u8],
    at: usize,
}

impl<'a> Cursor<'a> {
    /// A cursor at the start of a text.
    #[must_use]
    pub fn over(text: &'a [u8]) -> Self {
        Self { text, at: 0 }
    }

    /// Where the cursor stands.
    #[must_use]
    pub fn at(&self) -> usize {
        self.at
    }

    /// The letter of the next value's kind, without reading it.
    #[must_use]
    pub fn kind(&self) -> Option<u8> {
        self.text.get(self.at).copied()
    }

    /// That the whole text is read.
    ///
    /// # Errors
    ///
    /// Refuses a text with anything after its value.
    pub fn end(&self) -> Result<(), Refused> {
        if self.at == self.text.len() {
            Ok(())
        } else {
            Err(self.refused("the end of the value"))
        }
    }

    /// `N;`.
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    pub fn null(&mut self) -> Result<(), Refused> {
        self.word(b"N;", "null")
    }

    /// `b:0;` or `b:1;`.
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    pub fn boolean(&mut self) -> Result<bool, Refused> {
        self.word(b"b:", "a boolean")?;
        let value = match self.next() {
            Some(b'0') => false,
            Some(b'1') => true,
            _ => return Err(self.refused_before("0 or 1")),
        };
        self.word(b";", "a semicolon")?;

        Ok(value)
    }

    /// `i:<n>;`, an integer PHP holds.
    ///
    /// # Errors
    ///
    /// Refuses anything else, and an integer past PHP's.
    pub fn integer(&mut self) -> Result<i64, Refused> {
        self.word(b"i:", "an integer")?;
        let value = self.signed()?;
        self.word(b";", "a semicolon")?;

        Ok(value)
    }

    /// `d:<n>;`, a finite float as PHP reads it.
    ///
    /// # Errors
    ///
    /// Refuses anything else, and an infinity or NaN.
    pub fn float(&mut self) -> Result<f64, Refused> {
        self.word(b"d:", "a float")?;
        let start = self.at;
        let end = self.float_end()?;
        let value = self
            .text
            .get(start..end)
            .and_then(|digits| std::str::from_utf8(digits).ok())
            .and_then(|digits| digits.parse::<f64>().ok())
            .filter(|value| value.is_finite())
            .ok_or_else(|| self.refused("a finite float"))?;
        self.at = end;
        self.word(b";", "a semicolon")?;

        Ok(value)
    }

    /// An integer or a float, as a float, as PHP widens an integer.
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    #[allow(
        clippy::cast_precision_loss,
        reason = "PHP widens an integer to the nearest float, as `as` does"
    )]
    pub fn number(&mut self) -> Result<f64, Refused> {
        match self.kind() {
            Some(b'i') => self.integer().map(|value| value as f64),
            _ => self.float(),
        }
    }

    /// `s:<n>:"<bytes>";`, its bytes.
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    pub fn string(&mut self) -> Result<&'a [u8], Refused> {
        self.word(b"s:", "a string")?;
        let bytes = self.quoted()?;
        self.word(b";", "a semicolon")?;

        Ok(bytes)
    }

    /// `a:<n>:{`, the start of an array of so many entries: each a key and
    /// its value, then [`Cursor::close`].
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    pub fn array(&mut self) -> Result<usize, Refused> {
        self.word(b"a:", "an array")?;
        let count = self.length()?;
        self.word(b"{", "an opening brace")?;

        Ok(count)
    }

    /// `O:<n>:"<class>":<n>:{`, the start of an object of a class and so many
    /// properties: each a name and its value, then [`Cursor::close`].
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    pub fn object(&mut self) -> Result<(&'a [u8], usize), Refused> {
        self.word(b"O:", "an object")?;
        let class = self.quoted()?;
        self.word(b":", "a colon")?;
        let count = self.length()?;
        self.word(b"{", "an opening brace")?;

        Ok((class, count))
    }

    /// The key of an array's entry, or the name of an object's property.
    ///
    /// # Errors
    ///
    /// Refuses anything but an integer or a string.
    pub fn key(&mut self) -> Result<Key<'a>, Refused> {
        match self.kind() {
            Some(b'i') => self.integer().map(Key::Integer),
            Some(b's') => self.string().map(Key::Text),
            _ => Err(self.refused("a key")),
        }
    }

    /// `}`, the end of an array or an object.
    ///
    /// # Errors
    ///
    /// Refuses anything else.
    pub fn close(&mut self) -> Result<(), Refused> {
        self.word(b"}", "a closing brace")
    }

    /// Any value, read for its form alone, nested no deeper than [`DEEPEST`].
    ///
    /// # Errors
    ///
    /// Refuses a value that is not one of these, or nests deeper.
    pub fn skip(&mut self) -> Result<(), Refused> {
        self.skip_within(DEEPEST)
    }

    fn skip_within(&mut self, depth: usize) -> Result<(), Refused> {
        let deeper = depth
            .checked_sub(1)
            .ok_or_else(|| self.refused("a value nested less deeply"))?;

        match self.kind() {
            Some(b'N') => self.null(),
            Some(b'b') => self.boolean().map(drop),
            Some(b'i') => self.integer().map(drop),
            Some(b'd') => self.float().map(drop),
            Some(b's') => self.string().map(drop),
            Some(b'a') => {
                let count = self.array()?;
                self.skip_entries(count, deeper)
            }
            Some(b'O') => {
                let (_, count) = self.object()?;
                self.skip_entries(count, deeper)
            }
            _ => Err(self.refused("a value")),
        }
    }

    fn skip_entries(&mut self, count: usize, depth: usize) -> Result<(), Refused> {
        for _ in 0..count {
            self.key()?;
            self.skip_within(depth)?;
        }

        self.close()
    }

    /// `<n>:"<bytes>"`, the bytes.
    fn quoted(&mut self) -> Result<&'a [u8], Refused> {
        let length = self.length()?;
        self.word(b"\"", "a quote")?;
        let start = self.at;
        let end = start
            .checked_add(length)
            .filter(|end| *end <= self.text.len())
            .ok_or_else(|| self.refused("as many bytes as the length says"))?;
        let bytes = self.text.get(start..end).unwrap_or_default();
        self.at = end;
        self.word(b"\"", "a quote")?;

        Ok(bytes)
    }

    /// `<digits>:`, a length.
    fn length(&mut self) -> Result<usize, Refused> {
        let value = self.digits()?;
        self.word(b":", "a colon")?;

        Ok(usize::try_from(value).unwrap_or(usize::MAX))
    }

    /// `[+-]?<digits>`, an integer within PHP's.
    fn signed(&mut self) -> Result<i64, Refused> {
        let negative = match self.kind() {
            Some(b'-') => true,
            Some(b'+') => false,
            _ => return self.digits(),
        };
        self.at += 1;
        let start = self.at;
        let magnitude = self.digits_as_i128()?;
        let value = if negative { -magnitude } else { magnitude };

        i64::try_from(value).map_err(|_| Refused {
            at: start,
            expected: "an integer within PHP's",
        })
    }

    /// `<digits>`, an integer within PHP's.
    fn digits(&mut self) -> Result<i64, Refused> {
        let start = self.at;
        let value = self.digits_as_i128()?;

        i64::try_from(value).map_err(|_| Refused {
            at: start,
            expected: "an integer within PHP's",
        })
    }

    fn digits_as_i128(&mut self) -> Result<i128, Refused> {
        let start = self.at;
        let mut value: i128 = 0;

        while let Some(digit) = self.kind().filter(u8::is_ascii_digit) {
            value = value
                .checked_mul(10)
                .and_then(|value| value.checked_add(i128::from(digit - b'0')))
                .filter(|value| *value <= i128::from(i64::MAX) + 1)
                .ok_or_else(|| self.refused("an integer within PHP's"))?;
            self.at += 1;
        }

        if self.at == start {
            return Err(self.refused("a digit"));
        }

        Ok(value)
    }

    /// Where a float as PHP's reader takes one ends: an integer, or digits
    /// around a point, either with an exponent.
    fn float_end(&self) -> Result<usize, Refused> {
        let mut at = self.at;
        let sign = |at: usize| usize::from(matches!(self.text.get(at), Some(b'+' | b'-')));
        let digits = |from: usize| {
            self.text
                .get(from..)
                .unwrap_or_default()
                .iter()
                .take_while(|byte| byte.is_ascii_digit())
                .count()
        };

        at += sign(at);
        let whole = digits(at);
        at += whole;
        let mut fraction = 0;

        if self.text.get(at) == Some(&b'.') {
            fraction = digits(at + 1);
            at += 1 + fraction;
        }

        if whole + fraction == 0 {
            return Err(self.refused("a float's digits"));
        }

        if matches!(self.text.get(at), Some(b'e' | b'E')) {
            let signed = at + 1 + sign(at + 1);
            let exponent = digits(signed);

            if exponent == 0 {
                return Err(Refused {
                    at,
                    expected: "an exponent's digits",
                });
            }

            at = signed + exponent;
        }

        Ok(at)
    }

    fn word(&mut self, word: &[u8], expected: &'static str) -> Result<(), Refused> {
        if self
            .text
            .get(self.at..)
            .is_some_and(|rest| rest.starts_with(word))
        {
            self.at += word.len();
            Ok(())
        } else {
            Err(self.refused(expected))
        }
    }

    fn next(&mut self) -> Option<u8> {
        let byte = self.kind()?;
        self.at += 1;

        Some(byte)
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

#[cfg(test)]
mod tests {
    use super::{Cursor, DEEPEST, Key};

    fn read<'t, T>(text: &'t str, reading: impl FnOnce(&mut Cursor<'t>) -> T) -> T {
        reading(&mut Cursor::over(text.as_bytes()))
    }

    #[test]
    fn reads_each_scalar_as_php_writes_it() {
        assert!(read("N;", Cursor::null).is_ok());
        assert!(!read("b:0;", Cursor::boolean).unwrap());
        assert!(read("b:1;", Cursor::boolean).unwrap());
        assert_eq!(read("i:-42;", Cursor::integer).unwrap(), -42);
        assert_eq!(read("i:+7;", Cursor::integer).unwrap(), 7);
        assert_eq!(
            read("i:9223372036854775807;", Cursor::integer).unwrap(),
            i64::MAX
        );
        assert_eq!(
            read("i:-9223372036854775808;", Cursor::integer).unwrap(),
            i64::MIN
        );
        assert_eq!(
            read("d:0.005958292;", Cursor::float).unwrap(),
            0.005_958_292
        );
        assert_eq!(read("d:1.0E+25;", Cursor::float).unwrap(), 1.0e25);
        assert_eq!(read("d:-.5;", Cursor::float).unwrap(), -0.5);
        assert_eq!(read("d:5.;", Cursor::float).unwrap(), 5.0);
        assert_eq!(read("d:3;", Cursor::float).unwrap(), 3.0);
        assert_eq!(read("d:2e-3;", Cursor::float).unwrap(), 0.002);
        assert_eq!(read("i:2;", Cursor::number).unwrap(), 2.0);
        assert_eq!(read("d:2.5;", Cursor::number).unwrap(), 2.5);
        assert_eq!(read("s:3:\"a\"b\";", Cursor::string).unwrap(), b"a\"b");
        assert_eq!(read("s:0:\"\";", Cursor::string).unwrap(), b"");
    }

    #[test]
    fn refuses_what_php_reads_otherwise_or_not_at_all() {
        for (text, expected) in [
            ("b:2;", "0 or 1"),
            ("b:", "0 or 1"),
            ("b:1", "a semicolon"),
            ("i:1;", "a boolean"),
        ] {
            assert_eq!(
                read(text, Cursor::boolean).unwrap_err().expected,
                expected,
                "{text}"
            );
        }

        for (text, expected) in [
            ("i:;", "a digit"),
            ("i:-;", "a digit"),
            ("i:9223372036854775808;", "an integer within PHP's"),
            ("i:-9223372036854775809;", "an integer within PHP's"),
            ("i:+9223372036854775808;", "an integer within PHP's"),
            (
                "i:99999999999999999999999999999999999999999;",
                "an integer within PHP's",
            ),
            ("i:1", "a semicolon"),
            ("x:1;", "an integer"),
        ] {
            assert_eq!(
                read(text, Cursor::integer).unwrap_err().expected,
                expected,
                "{text}"
            );
        }

        for (text, expected) in [
            ("d:INF;", "a float's digits"),
            ("d:NAN;", "a float's digits"),
            ("d:-INF;", "a float's digits"),
            ("d:.;", "a float's digits"),
            ("d:1e;", "an exponent's digits"),
            ("d:1e+;", "an exponent's digits"),
            ("d:1e999;", "a finite float"),
            ("d:1.5", "a semicolon"),
            ("i:1;", "a float"),
        ] {
            assert_eq!(
                read(text, Cursor::float).unwrap_err().expected,
                expected,
                "{text}"
            );
        }

        for (text, expected) in [
            ("s:9:\"abc\";", "as many bytes as the length says"),
            ("s:4:\"abc\";", "a quote"),
            ("s:1:\"ab\";", "a quote"),
            ("s:1:'a';", "a quote"),
            ("s:x:\"a\";", "a digit"),
            ("s:1\"a\";", "a colon"),
            ("s:1:\"a\"", "a semicolon"),
            ("s:99999999999999999999:\"a\";", "an integer within PHP's"),
            ("S:1:\"a\";", "a string"),
        ] {
            assert_eq!(
                read(text, Cursor::string).unwrap_err().expected,
                expected,
                "{text}"
            );
        }
    }

    #[test]
    fn reads_arrays_and_objects_entry_by_entry() {
        let mut cursor = Cursor::over(b"a:2:{i:3;N;s:1:\"k\";b:1;}O:3:\"Cls\":1:{s:1:\"p\";i:1;}");

        assert_eq!(cursor.array().unwrap(), 2);
        assert_eq!(cursor.key().unwrap(), Key::Integer(3));
        cursor.null().unwrap();
        assert_eq!(cursor.key().unwrap(), Key::Text(b"k"));
        assert!(cursor.boolean().unwrap());
        cursor.close().unwrap();
        assert_eq!(cursor.object().unwrap(), (&b"Cls"[..], 1));
        assert_eq!(cursor.key().unwrap(), Key::Text(b"p"));
        assert_eq!(cursor.integer().unwrap(), 1);
        cursor.close().unwrap();
        cursor.end().unwrap();
        assert_eq!(cursor.at(), 50);
        assert_eq!(read("d:1;", Cursor::key).unwrap_err().expected, "a key");
        assert_eq!(
            read("a:1:[", Cursor::array).unwrap_err().expected,
            "an opening brace"
        );
        assert_eq!(
            read("O:1:\"C\"1:{", Cursor::object).unwrap_err().expected,
            "a colon"
        );
        assert_eq!(
            read("O:1:\"C\":1:[", Cursor::object).unwrap_err().expected,
            "an opening brace"
        );
        assert_eq!(read("N", Cursor::null).unwrap_err().expected, "null");
        assert_eq!(
            read("]", Cursor::close).unwrap_err().expected,
            "a closing brace"
        );
        assert_eq!(
            Cursor::over(b"N;N;").end().unwrap_err().expected,
            "the end of the value"
        );
    }

    #[test]
    fn skips_any_value_it_reads_and_nothing_else() {
        let value = "a:4:{i:0;O:1:\"C\":2:{s:1:\"a\";d:1.5;s:1:\"b\";a:0:{}}i:1;s:2:\"xy\";s:1:\"z\";a:2:{i:0;b:0;i:1;i:5;}i:2;N;}";
        let mut cursor = Cursor::over(value.as_bytes());

        cursor.skip().unwrap();
        cursor.end().unwrap();

        for refused in [
            "r:1;",
            "R:1;",
            "C:1:\"C\":0:{}",
            "E:3:\"A:B\";",
            "",
            "a:1:{N;N;}",
            "a:1:{i:0;N;",
        ] {
            assert!(
                Cursor::over(refused.as_bytes()).skip().is_err(),
                "{refused}"
            );
        }

        let deep = format!("{}N;{}", "a:1:{i:0;".repeat(DEEPEST), "}".repeat(DEEPEST));
        let deepest = format!(
            "{}N;{}",
            "a:1:{i:0;".repeat(DEEPEST - 1),
            "}".repeat(DEEPEST - 1)
        );

        assert_eq!(
            Cursor::over(deep.as_bytes()).skip().unwrap_err().expected,
            "a value nested less deeply"
        );
        assert!(Cursor::over(deepest.as_bytes()).skip().is_ok());
    }
}
