//! Values written as the gate's PHP writes them with `json_encode()` and its
//! flags (`JsonText::FLAGS`): slashes and Unicode unescaped, every float with
//! a fraction, at PHP's `serialize_precision` of -1.

/// A string as JSON text: `"` and `\` escaped, the control characters as
/// `\b`, `\f`, `\n`, `\r`, `\t` or `\u00xx`, and the line and paragraph
/// separators as ` ` and ` `, which PHP escapes without
/// `JSON_UNESCAPED_LINE_TERMINATORS`.
pub fn string(out: &mut Vec<u8>, text: &str) {
    out.push(b'"');

    for character in text.chars() {
        match character {
            '"' => out.extend_from_slice(b"\\\""),
            '\\' => out.extend_from_slice(b"\\\\"),
            '\u{8}' => out.extend_from_slice(b"\\b"),
            '\u{c}' => out.extend_from_slice(b"\\f"),
            '\n' => out.extend_from_slice(b"\\n"),
            '\r' => out.extend_from_slice(b"\\r"),
            '\t' => out.extend_from_slice(b"\\t"),
            '\u{0}'..='\u{1f}' | '\u{2028}' | '\u{2029}' => {
                out.extend_from_slice(format!("\\u{:04x}", u32::from(character)).as_bytes());
            }
            other => {
                let mut bytes = [0; 4];
                out.extend_from_slice(other.encode_utf8(&mut bytes).as_bytes());
            }
        }
    }

    out.push(b'"');
}

/// A finite float as JSON text, as PHP writes one: the fewest digits that
/// read back as it (`php_gcvt` with 17 digits in `zend_dtoa`'s mode 0), in
/// exponential form where its point stands more than 3 places left of its
/// first digit or more than 17 right, and with `.0` where it has no point.
pub fn float(out: &mut Vec<u8>, value: f64) {
    let shortest = format!("{:e}", value.abs());
    let (mantissa, exponent) = shortest.split_once('e').unwrap_or((&shortest, "0"));
    let digits: String = mantissa.chars().filter(char::is_ascii_digit).collect();
    let point = exponent.parse::<i32>().unwrap_or_default() + 1;
    let mut text = String::new();

    if value.is_sign_negative() {
        text.push('-');
    }

    if !(-3..=17).contains(&point) {
        exponential(&mut text, &digits, point - 1);
    } else if point <= 0 {
        text.push_str("0.");
        text.push_str(&"0".repeat(point.unsigned_abs() as usize));
        text.push_str(&digits);
    } else {
        positional(&mut text, &digits, point.unsigned_abs() as usize);
    }

    if !text.contains('.') {
        text.push_str(".0");
    }

    out.extend_from_slice(text.as_bytes());
}

/// `d.ddde±x`, as `php_gcvt` writes a float in exponential form.
fn exponential(text: &mut String, digits: &str, exponent: i32) {
    let mut characters = digits.chars();

    text.extend(characters.next());
    text.push('.');

    match characters.as_str() {
        "" => text.push('0'),
        rest => text.push_str(rest),
    }

    text.push('e');
    text.push(if exponent < 0 { '-' } else { '+' });
    text.push_str(&exponent.unsigned_abs().to_string());
}

/// The digits with the point after so many of them, padded with noughts.
fn positional(text: &mut String, digits: &str, point: usize) {
    let (whole, fraction) = digits.split_at(point.min(digits.len()));

    text.push_str(whole);
    text.push_str(&"0".repeat(point.saturating_sub(digits.len())));

    if !fraction.is_empty() {
        text.push('.');
        text.push_str(fraction);
    }
}

#[cfg(test)]
mod tests {
    use super::{float, string};

    fn floated(value: f64) -> String {
        let mut out = Vec::new();
        float(&mut out, value);

        String::from_utf8(out).unwrap()
    }

    fn stringed(text: &str) -> String {
        let mut out = Vec::new();
        string(&mut out, text);

        String::from_utf8(out).unwrap()
    }

    #[test]
    fn writes_each_float_as_php_writes_it() {
        for (value, written) in [
            (0.0, "0.0"),
            (-0.0, "-0.0"),
            (2.0, "2.0"),
            (0.25, "0.25"),
            (-1.5, "-1.5"),
            (0.000_409_042, "0.000409042"),
            (0.000_1, "0.0001"),
            (0.000_012_345, "1.2345e-5"),
            (1.5e-7, "1.5e-7"),
            (1.0e25, "1.0e+25"),
            (1.0e17, "1.0e+17"),
            (1.0e16, "10000000000000000.0"),
            (123_456_789.125, "123456789.125"),
            (1.0e-10, "1.0e-10"),
            (1.0e100, "1.0e+100"),
            (0.1 + 0.2, "0.30000000000000004"),
            (f64::MAX, "1.7976931348623157e+308"),
            (f64::MIN_POSITIVE, "2.2250738585072014e-308"),
            (5e-324, "5.0e-324"),
            (1.0e15, "1000000000000000.0"),
            (123_456_789_012_345_680.0, "1.2345678901234568e+17"),
            (9.999e-5, "9.999e-5"),
        ] {
            assert_eq!(floated(value), written, "{value:e}");
        }
    }

    #[test]
    fn writes_each_string_as_php_writes_it() {
        assert_eq!(stringed("P\\Tests\\A::it /x"), r#""P\\Tests\\A::it /x""#);
        assert_eq!(stringed("a \"b\" é"), "\"a \\\"b\\\" é\"");
        assert_eq!(
            stringed("\u{8}\u{c}\n\r\t\u{0}\u{1f}\u{7f}"),
            "\"\\b\\f\\n\\r\\t\\u0000\\u001f\u{7f}\""
        );
        assert_eq!(
            stringed("\u{2028}\u{2029}\u{1f600}"),
            "\"\\u2028\\u2029\u{1f600}\""
        );
    }
}
