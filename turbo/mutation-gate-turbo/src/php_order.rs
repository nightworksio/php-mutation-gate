//! The order PHP's `sort()` puts strings in, where it can be reproduced exactly.
//!
//! PHP compares two strings as numbers when both are numeric strings, and byte by byte otherwise. Over strings
//! where at most one is numeric, or where all are, that is a total order, and PHP's stable sort gives the one
//! result any stable sort gives. Where two or more are numeric and another is not, the comparison is not
//! transitive and the result depends on PHP's own sorting algorithm, so the order is refused, never guessed.

use std::cmp::Ordering;

/// Why an order cannot be reproduced: the strings mix numeric and other strings, or hold a number past PHP's integers.
#[derive(Debug, PartialEq, Eq)]
pub struct Unreproducible;

#[derive(Clone, Copy, Debug, PartialEq)]
enum Numeric {
    Integer(i64),
    Float(f64),
    Overflowed,
}

/// The strings in the order PHP's `sort()` gives them, or why that order cannot be reproduced.
///
/// # Errors
///
/// [`Unreproducible`] where PHP's order depends on its sorting algorithm or on a number past its integers.
pub fn sorted<T: AsRef<[u8]>>(mut items: Vec<T>) -> Result<Vec<T>, Unreproducible> {
    let numeric: Vec<Option<Numeric>> = items.iter().map(|item| numeric(item.as_ref())).collect();
    let count = numeric.iter().filter(|value| value.is_some()).count();

    if count < 2 {
        items.sort_by(|a, b| a.as_ref().cmp(b.as_ref()));

        return Ok(items);
    }

    if count < items.len() || numeric.iter().flatten().any(|value| *value == Numeric::Overflowed) {
        return Err(Unreproducible);
    }

    let mut paired: Vec<(Numeric, T)> = numeric.into_iter().flatten().zip(items).collect();
    paired.sort_by(|a, b| compared(a.0, b.0));

    Ok(paired.into_iter().map(|(_, item)| item).collect())
}

fn compared(a: Numeric, b: Numeric) -> Ordering {
    match (a, b) {
        (Numeric::Integer(x), Numeric::Integer(y)) => x.cmp(&y),
        _ => as_float(a).partial_cmp(&as_float(b)).unwrap_or(Ordering::Equal),
    }
}

#[allow(clippy::cast_precision_loss)]
fn as_float(value: Numeric) -> f64 {
    match value {
        Numeric::Integer(integer) => integer as f64,
        Numeric::Float(float) => float,
        Numeric::Overflowed => f64::NAN,
    }
}

const fn is_space(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c)
}

/// The number a string spells as PHP 8 reads a numeric string with no trailing data allowed, or none.
fn numeric(text: &[u8]) -> Option<Numeric> {
    let start = text.iter().position(|byte| !is_space(*byte))?;
    let end = text
        .iter()
        .rposition(|byte| !is_space(*byte))
        .map_or(start, |at| at + 1);
    let body = text.get(start..end)?;
    let unsigned = match body.first() {
        Some(b'+' | b'-') => body.get(1..)?,
        _ => body,
    };
    let integer = unsigned.iter().take_while(|byte| byte.is_ascii_digit()).count();
    let mut at = integer;
    let mut fraction = 0;

    if unsigned.get(at) == Some(&b'.') {
        fraction = unsigned
            .get(at + 1..)?
            .iter()
            .take_while(|byte| byte.is_ascii_digit())
            .count();
        at += 1 + fraction;
    }

    if integer == 0 && fraction == 0 {
        return None;
    }

    let decimal = at > integer;
    let mut exponent = false;

    if matches!(unsigned.get(at), Some(b'e' | b'E')) {
        let signed = usize::from(matches!(unsigned.get(at + 1), Some(b'+' | b'-')));
        let digits = unsigned
            .get(at + 1 + signed..)?
            .iter()
            .take_while(|byte| byte.is_ascii_digit())
            .count();

        if digits > 0 {
            at += 1 + signed + digits;
            exponent = true;
        }
    }

    if at != unsigned.len() {
        return None;
    }

    let spelt = std::str::from_utf8(body).ok()?;

    if decimal || exponent {
        return spelt.parse::<f64>().ok().map(Numeric::Float);
    }

    Some(spelt.parse::<i64>().map_or(Numeric::Overflowed, Numeric::Integer))
}

#[cfg(test)]
mod tests {
    use super::{Numeric, Unreproducible, numeric, sorted};

    #[test]
    fn strings_with_no_number_sort_byte_by_byte_and_shorter_first() {
        let order = sorted(vec!["tests/9", "b", "tests/10", "a", "", "é", "Abc", "ab", "a"]);

        assert_eq!(
            order,
            Ok(vec!["", "Abc", "a", "a", "ab", "b", "tests/10", "tests/9", "é"])
        );
    }

    #[test]
    fn one_numeric_string_among_others_sorts_byte_by_byte() {
        assert_eq!(sorted(vec!["b", "10", "a", "9a"]), Ok(vec!["10", "9a", "a", "b"]));
    }

    #[test]
    fn numeric_strings_alone_sort_by_value_keeping_equals_in_their_order() {
        let order = sorted(vec!["10", " 9", "1e1", "01", "1", "-2", ".5", "1.", "+3", "7 "]);

        assert_eq!(
            order,
            Ok(vec!["-2", ".5", "01", "1", "1.", "+3", "7 ", " 9", "10", "1e1"])
        );
    }

    #[test]
    fn numbers_mixed_with_other_strings_are_refused() {
        assert_eq!(sorted(vec!["10", "9", "a"]), Err(Unreproducible));
    }

    #[test]
    fn a_number_past_php_integers_is_refused() {
        assert_eq!(sorted(vec!["1", "9223372036854775808"]), Err(Unreproducible));
    }

    #[test]
    fn php_numeric_strings_are_recognised_as_php_8_reads_them() {
        for spelt in [
            "0", "01", " 7", "7 ", "\t7\n", "-2", "+3", ".5", "1.", "1.e5", "1e3", "1E-3", "-.5e+2",
        ] {
            assert!(numeric(spelt.as_bytes()).is_some(), "{spelt} is numeric");
        }

        for spelt in [
            "", " ", ".", "e5", "5e", "5e+", "0x1A", "1_0", "INF", "nan", "1 2", "--1", "+", "1.2.3", "é",
        ] {
            assert_eq!(numeric(spelt.as_bytes()), None, "{spelt} is not numeric");
        }
    }

    #[test]
    fn integers_within_php_integers_stay_exact() {
        assert_eq!(numeric(b"9223372036854775807"), Some(Numeric::Integer(i64::MAX)));
        assert_eq!(numeric(b"-9223372036854775808"), Some(Numeric::Integer(i64::MIN)));
        assert_eq!(numeric(b"9223372036854775808"), Some(Numeric::Overflowed));
        assert_eq!(numeric(b"1e400"), Some(Numeric::Float(f64::INFINITY)));
    }
}
