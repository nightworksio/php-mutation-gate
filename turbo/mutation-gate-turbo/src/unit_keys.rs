//! Each unit's proof key in the key's fifth format, as `ContentKeys::keyReading()` writes it (ADR-0007).
//!
//! A unit's key hashes the format, the run's base and the digest of what its judging test files read, then the
//! unit's path and what judges it, then each covered line, in the order the gate gives them, with the digest of
//! the set of tests that cover it. A set's digest hashes its count and its test ids in byte order, and is worked
//! out once however many lines share the set.

use crate::framing::{framed, hex};
use crate::refusal::Refusal;
use serde_json::Value;
use sha2::{Digest, Sha256};
use std::thread;

/// One unit as the gate asks it: its path, what judges it, the digest of what its judges read, and its lines.
#[derive(Debug)]
struct Unit {
    path: String,
    judged_by: String,
    read: String,
    lines: Vec<(String, usize)>,
}

/// What the gate asks: the base, every test id, every distinct set of tests by place, and each unit.
#[derive(Debug)]
pub struct Asked {
    format: String,
    base: String,
    tests: Vec<String>,
    sets: Vec<Vec<usize>>,
    units: Vec<Unit>,
}

impl Asked {
    /// The request as the gate writes it, every index checked against what it names.
    ///
    /// # Errors
    ///
    /// A [`Refusal`] where a field is missing, of the wrong type, or names a test or set that is not there.
    pub fn read(request: &Value) -> Result<Self, Refusal> {
        let tests = strings(field(request, "tests")?, "tests")?;
        let sets = list(field(request, "sets")?, "sets")?
            .iter()
            .map(|set| indexes(set, "sets", tests.len()))
            .collect::<Result<Vec<_>, _>>()?;
        let units = list(field(request, "units")?, "units")?
            .iter()
            .map(|unit| unit_of(unit, sets.len()))
            .collect::<Result<Vec<_>, _>>()?;

        Ok(Self {
            format: text(field(request, "format")?, "format")?,
            base: text(field(request, "base")?, "base")?,
            tests,
            sets,
            units,
        })
    }

    /// Each unit's key, in the order the units were asked, worked out on up to this many threads.
    ///
    /// # Errors
    ///
    /// A [`Refusal`] where a worker thread stops.
    pub fn keys(&self, threads: usize) -> Result<Vec<String>, Refusal> {
        let digests = in_parallel(&self.sets, threads, |set| Ok(self.set_digest(set)))?;

        in_parallel(&self.units, threads, |unit| Ok(self.unit_key(unit, &digests)))
    }

    fn set_digest(&self, set: &[usize]) -> String {
        let mut ids: Vec<&[u8]> = set
            .iter()
            .filter_map(|at| self.tests.get(*at).map(String::as_bytes))
            .collect();
        ids.sort_unstable();
        let mut hash = Sha256::new();
        framed(&mut hash, ids.len().to_string().as_bytes());

        for id in ids {
            framed(&mut hash, id);
        }

        hex(hash)
    }

    fn unit_key(&self, unit: &Unit, digests: &[String]) -> String {
        let mut hash = Sha256::new();

        for field in [
            &self.format,
            &self.base,
            &unit.read,
            "unit",
            &unit.path,
            &unit.judged_by,
        ] {
            framed(&mut hash, field.as_bytes());
        }

        framed(&mut hash, unit.lines.len().to_string().as_bytes());

        for (line, set) in &unit.lines {
            framed(&mut hash, line.as_bytes());
            framed(&mut hash, digests.get(*set).map_or("", String::as_str).as_bytes());
        }

        hex(hash)
    }
}

/// Each item's answer, in order, worked out on up to this many threads.
fn in_parallel<T: Sync, F>(items: &[T], threads: usize, work: F) -> Result<Vec<String>, Refusal>
where
    F: Fn(&T) -> Result<String, Refusal> + Sync,
{
    let size = items.len().div_ceil(threads.max(1)).max(1);

    thread::scope(|scope| {
        let workers: Vec<_> = items
            .chunks(size)
            .map(|chunk| scope.spawn(|| chunk.iter().map(&work).collect::<Result<Vec<_>, _>>()))
            .collect();
        let mut answers = Vec::with_capacity(items.len());

        for worker in workers {
            answers.extend(
                worker
                    .join()
                    .map_err(|_| Refusal::because("a worker thread stopped"))??,
            );
        }

        Ok(answers)
    })
}

fn unit_of(unit: &Value, sets: usize) -> Result<Unit, Refusal> {
    let lines = list(field(unit, "lines")?, "lines")?
        .iter()
        .map(|line| match line.as_array().map(Vec::as_slice) {
            Some([number, set]) => Ok((
                number
                    .as_u64()
                    .map(|number| number.to_string())
                    .ok_or_else(|| Refusal::because("lines: a line is not a whole number"))?,
                index(set, "lines", sets)?,
            )),
            _ => Err(Refusal::because("lines: each is a line and its set")),
        })
        .collect::<Result<Vec<_>, _>>()?;

    Ok(Unit {
        path: text(field(unit, "path")?, "path")?,
        judged_by: text(field(unit, "judgedBy")?, "judgedBy")?,
        read: text(field(unit, "read")?, "read")?,
        lines,
    })
}

fn field<'a>(value: &'a Value, name: &str) -> Result<&'a Value, Refusal> {
    value
        .get(name)
        .ok_or_else(|| Refusal::because(&format!("{name}: missing")))
}

fn text(value: &Value, name: &str) -> Result<String, Refusal> {
    value
        .as_str()
        .map(str::to_owned)
        .ok_or_else(|| Refusal::because(&format!("{name}: not a string")))
}

fn list<'a>(value: &'a Value, name: &str) -> Result<&'a Vec<Value>, Refusal> {
    value
        .as_array()
        .ok_or_else(|| Refusal::because(&format!("{name}: not a list")))
}

fn strings(value: &Value, name: &str) -> Result<Vec<String>, Refusal> {
    list(value, name)?.iter().map(|item| text(item, name)).collect()
}

fn index(value: &Value, name: &str, count: usize) -> Result<usize, Refusal> {
    value
        .as_u64()
        .and_then(|index| usize::try_from(index).ok())
        .filter(|index| *index < count)
        .ok_or_else(|| Refusal::because(&format!("{name}: an index names nothing")))
}

fn indexes(value: &Value, name: &str, count: usize) -> Result<Vec<usize>, Refusal> {
    list(value, name)?.iter().map(|item| index(item, name, count)).collect()
}

#[cfg(test)]
mod tests {
    use super::Asked;
    use serde_json::json;
    use sha2::{Digest, Sha256};

    fn sha(text: &str) -> String {
        let mut hash = Sha256::new();
        hash.update(text.as_bytes());

        crate::framing::hex(hash)
    }

    fn asked(request: &serde_json::Value) -> Asked {
        match Asked::read(request) {
            Ok(asked) => asked,
            Err(refusal) => unreachable!("{}", refusal.why()),
        }
    }

    #[test]
    fn a_unit_key_hashes_its_lines_with_the_digest_of_their_test_set_in_byte_order() {
        let request = json!({
            "format": "mutation-gate proof 5", "base": "b4",
            "tests": ["b::t", "a::t", "é::t", "B::t"],
            "sets": [[0, 1, 2, 3], [1]],
            "units": [
                {"path": "src/A.php", "judgedBy": "", "read": "r1", "lines": [[3, 0], [7, 1], [9, 0]]},
                {"path": "src/B.php", "judgedBy": "group", "read": "r2", "lines": []},
            ],
        });
        let wide = sha("1:4\n4:B::t\n4:a::t\n4:b::t\n5:é::t\n");
        let narrow = sha("1:1\n4:a::t\n");
        let a = sha(&format!(
            "21:mutation-gate proof 5\n2:b4\n2:r1\n4:unit\n9:src/A.php\n0:\n1:3\n1:3\n64:{wide}\n1:7\n64:{narrow}\n1:9\n64:{wide}\n"
        ));
        let b = sha("21:mutation-gate proof 5\n2:b4\n2:r2\n4:unit\n9:src/B.php\n5:group\n1:0\n");

        assert_eq!(asked(&request).keys(3), Ok(vec![a, b]));
    }

    #[test]
    fn keys_come_back_in_the_order_asked_on_any_number_of_threads() {
        let units: Vec<_> = (0..40)
            .map(|at| json!({"path": format!("src/F{at}.php"), "judgedBy": "", "read": "r", "lines": [[at, at % 2]]}))
            .collect();
        let request = json!({
            "format": "f", "base": "b", "tests": ["x", "y"], "sets": [[0], [0, 1]], "units": units,
        });

        assert_eq!(asked(&request).keys(1), asked(&request).keys(7));
        assert_eq!(asked(&request).keys(1), asked(&request).keys(100));
    }

    #[test]
    fn an_index_or_line_that_names_nothing_is_refused() {
        let unknown_test = json!({"format": "f", "base": "b", "tests": ["x"], "sets": [[1]], "units": []});
        let unknown_set = json!({
            "format": "f", "base": "b", "tests": ["x"], "sets": [[0]],
            "units": [{"path": "p", "judgedBy": "", "read": "r", "lines": [[1, 1]]}],
        });
        let not_a_line = json!({
            "format": "f", "base": "b", "tests": ["x"], "sets": [[0]],
            "units": [{"path": "p", "judgedBy": "", "read": "r", "lines": [["1", 0]]}],
        });
        let not_a_pair = json!({
            "format": "f", "base": "b", "tests": ["x"], "sets": [[0]],
            "units": [{"path": "p", "judgedBy": "", "read": "r", "lines": [[1]]}],
        });

        for request in [unknown_test, unknown_set, not_a_line, not_a_pair] {
            assert!(Asked::read(&request).is_err());
        }
    }
}
