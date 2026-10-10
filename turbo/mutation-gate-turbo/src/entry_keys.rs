//! The key of each test file's coverage entries, as `EntryKeying::keyOf()` writes it (ADR-0023, decision 1).
//!
//! Each entry starts from its test file, the files its tests executed and the files every entry reads, follows
//! the name graph to every file those name, and hashes the format, the base and every file reached with its
//! digest, in the order PHP's `sort()` gives the paths.

use crate::framing::{framed, hex};
use crate::php_order::{Unreproducible, sorted};
use crate::refusal::Refusal;
use serde_json::Value;
use sha2::{Digest, Sha256};
use std::thread;

/// What the gate asks: the paths, each with its digest and the files it names, and each entry's starting files.
#[derive(Debug)]
pub struct Asked {
    format: String,
    base: String,
    paths: Vec<String>,
    digests: Vec<String>,
    edges: Vec<Vec<usize>>,
    always: Vec<usize>,
    entries: Vec<Vec<usize>>,
}

impl Asked {
    /// The request as the gate writes it, every index checked against the paths it names.
    ///
    /// # Errors
    ///
    /// A [`Refusal`] where a field is missing, of the wrong type, or names a path that is not there.
    pub fn read(request: &Value) -> Result<Self, Refusal> {
        let paths = strings(field(request, "paths")?, "paths")?;
        let digests = strings(field(request, "digests")?, "digests")?;
        let count = paths.len();

        if digests.len() != count {
            return Err(Refusal::because("digests: one is needed for each path"));
        }

        let edges = lists(field(request, "edges")?, "edges", count)?;

        if edges.len() != count {
            return Err(Refusal::because("edges: one list is needed for each path"));
        }

        Ok(Self {
            format: text(field(request, "format")?, "format")?,
            base: text(field(request, "base")?, "base")?,
            paths,
            digests,
            edges,
            always: indexes(field(request, "always")?, "always", count)?,
            entries: lists(field(request, "entries")?, "entries", count)?,
        })
    }

    /// Each entry's key, in the order the entries were asked, worked out on up to this many threads.
    ///
    /// # Errors
    ///
    /// A [`Refusal`] where PHP's order of an entry's paths cannot be reproduced.
    pub fn keys(&self, threads: usize) -> Result<Vec<String>, Refusal> {
        let size = self.entries.len().div_ceil(threads.max(1)).max(1);

        thread::scope(|scope| {
            let workers: Vec<_> = self
                .entries
                .chunks(size)
                .map(|chunk| scope.spawn(move || self.keys_of(chunk)))
                .collect();
            let mut keys = Vec::with_capacity(self.entries.len());

            for worker in workers {
                keys.extend(
                    worker
                        .join()
                        .map_err(|_| Refusal::because("a worker thread stopped"))??,
                );
            }

            Ok(keys)
        })
    }

    fn keys_of(&self, entries: &[Vec<usize>]) -> Result<Vec<String>, Refusal> {
        let mut walk = Walk::over(self.paths.len());

        entries
            .iter()
            .map(|starts| self.key_of(walk.from(starts.iter().chain(&self.always).copied(), &self.edges)))
            .collect()
    }

    fn key_of(&self, reached: &[usize]) -> Result<String, Refusal> {
        let named: Vec<(&[u8], usize)> = reached
            .iter()
            .filter_map(|at| self.paths.get(*at).map(|path| (path.as_bytes(), *at)))
            .collect();
        let order = sorted(named.into_iter().map(Keyed).collect()).map_err(|Unreproducible| {
            Refusal::because("the paths mix numeric strings with others, whose order only PHP can give")
        })?;
        let mut hash = Sha256::new();
        framed(&mut hash, self.format.as_bytes());
        framed(&mut hash, self.base.as_bytes());
        framed(&mut hash, order.len().to_string().as_bytes());

        for Keyed((path, at)) in order {
            framed(&mut hash, path);
            framed(
                &mut hash,
                self.digests.get(at).map_or(&[][..], |digest| digest.as_bytes()),
            );
        }

        Ok(hex(hash))
    }
}

struct Keyed<'a>((&'a [u8], usize));

impl AsRef<[u8]> for Keyed<'_> {
    fn as_ref(&self) -> &[u8] {
        self.0.0
    }
}

/// A breadth-first walk of the name graph, reusing one mark per path across walks.
struct Walk {
    marks: Vec<u32>,
    round: u32,
    queue: Vec<usize>,
}

impl Walk {
    fn over(count: usize) -> Self {
        Self {
            marks: vec![0; count],
            round: 0,
            queue: Vec::new(),
        }
    }

    /// These paths and every path they name, transitively, each once, in the order `NamedFiles::walk()` reaches them.
    fn from(&mut self, starts: impl Iterator<Item = usize>, edges: &[Vec<usize>]) -> &[usize] {
        if self.round == u32::MAX {
            self.marks.fill(0);
            self.round = 0;
        }

        self.round += 1;
        self.queue.clear();

        for start in starts {
            self.reach(start);
        }

        let mut at = 0;

        while let Some(file) = self.queue.get(at).copied() {
            for next in edges.get(file).map_or(&[][..], Vec::as_slice) {
                self.reach(*next);
            }

            at += 1;
        }

        &self.queue
    }

    fn reach(&mut self, file: usize) {
        if let Some(mark) = self.marks.get_mut(file)
            && *mark != self.round
        {
            *mark = self.round;
            self.queue.push(file);
        }
    }
}

fn field<'a>(request: &'a Value, name: &str) -> Result<&'a Value, Refusal> {
    request
        .get(name)
        .ok_or_else(|| Refusal::because(&format!("{name}: missing")))
}

fn text(value: &Value, name: &str) -> Result<String, Refusal> {
    value
        .as_str()
        .map(str::to_owned)
        .ok_or_else(|| Refusal::because(&format!("{name}: not a string")))
}

fn strings(value: &Value, name: &str) -> Result<Vec<String>, Refusal> {
    value
        .as_array()
        .ok_or_else(|| Refusal::because(&format!("{name}: not a list")))?
        .iter()
        .map(|item| text(item, name))
        .collect()
}

fn indexes(value: &Value, name: &str, count: usize) -> Result<Vec<usize>, Refusal> {
    value
        .as_array()
        .ok_or_else(|| Refusal::because(&format!("{name}: not a list")))?
        .iter()
        .map(|item| {
            item.as_u64()
                .and_then(|index| usize::try_from(index).ok())
                .filter(|index| *index < count)
                .ok_or_else(|| Refusal::because(&format!("{name}: an index names no path")))
        })
        .collect()
}

fn lists(value: &Value, name: &str, count: usize) -> Result<Vec<Vec<usize>>, Refusal> {
    value
        .as_array()
        .ok_or_else(|| Refusal::because(&format!("{name}: not a list")))?
        .iter()
        .map(|item| indexes(item, name, count))
        .collect()
}

#[cfg(test)]
mod tests {
    use super::Asked;
    use serde_json::json;
    use sha2::Digest;
    use std::fmt::Write;

    fn asked(entries: &serde_json::Value) -> Asked {
        let request = json!({
            "format": "mutation-gate coverage entry 1",
            "base": "b4",
            "paths": ["tests/MoneyTest.php", "src/Money.php", "src/Currency.php", "tests/Pest.php", "src/Unused.php"],
            "digests": ["m", "s", "c", "p", "missing"],
            "edges": [[1], [2], [1], [], []],
            "always": [3],
            "entries": entries,
        });

        match Asked::read(&request) {
            Ok(asked) => asked,
            Err(refusal) => unreachable!("{}", refusal.why()),
        }
    }

    fn php_key(read: &[(&str, &str)]) -> String {
        let count = read.len().to_string();
        let mut text = format!("30:mutation-gate coverage entry 1\n2:b4\n{}:{count}\n", count.len());

        for (path, digest) in read {
            let _ = write!(text, "{}:{path}\n{}:{digest}\n", path.len(), digest.len());
        }

        let mut hash = sha2::Sha256::new();
        hash.update(text.as_bytes());

        crate::framing::hex(hash)
    }

    #[test]
    fn an_entry_reads_its_files_every_file_they_name_and_what_every_entry_reads_sorted() {
        let keys = asked(&json!([[0], [4]])).keys(2);

        assert_eq!(
            keys,
            Ok(vec![
                php_key(&[
                    ("src/Currency.php", "c"),
                    ("src/Money.php", "s"),
                    ("tests/MoneyTest.php", "m"),
                    ("tests/Pest.php", "p"),
                ]),
                php_key(&[("src/Unused.php", "missing"), ("tests/Pest.php", "p")]),
            ])
        );
    }

    #[test]
    fn keys_come_back_in_the_order_asked_on_any_number_of_threads() {
        let entries = json!([[0], [4], [1], [2], [0, 4], [3]]);

        assert_eq!(asked(&entries).keys(1), asked(&entries).keys(4));
        assert_eq!(asked(&entries).keys(1), asked(&entries).keys(64));
    }

    #[test]
    fn a_walk_reached_after_every_round_is_used_starts_its_marks_again() {
        let mut walk = super::Walk::over(2);
        walk.round = u32::MAX;
        let edges = vec![vec![1], vec![]];

        assert_eq!(walk.from([0].into_iter(), &edges), &[0, 1]);
        assert_eq!(walk.from([1].into_iter(), &edges), &[1]);
    }

    #[test]
    fn an_index_that_names_no_path_is_refused() {
        let request = json!({
            "format": "f", "base": "b", "paths": ["a"], "digests": ["d"], "edges": [[1]], "always": [], "entries": [],
        });

        assert!(Asked::read(&request).is_err());
    }

    #[test]
    fn a_digest_or_edge_list_missing_for_a_path_is_refused() {
        let digests = json!({
            "format": "f", "base": "b", "paths": ["a", "b"], "digests": ["d"], "edges": [[], []], "always": [], "entries": [],
        });
        let edges = json!({
            "format": "f", "base": "b", "paths": ["a"], "digests": ["d"], "edges": [], "always": [], "entries": [],
        });

        assert!(Asked::read(&digests).is_err());
        assert!(Asked::read(&edges).is_err());
    }

    #[test]
    fn paths_whose_php_order_cannot_be_reproduced_are_refused() {
        let request = json!({
            "format": "f", "base": "b", "paths": ["10", "9", "a"], "digests": ["x", "y", "z"],
            "edges": [[], [], []], "always": [], "entries": [[0, 1, 2]],
        });

        let asked = match Asked::read(&request) {
            Ok(asked) => asked,
            Err(refusal) => unreachable!("{}", refusal.why()),
        };

        assert!(asked.keys(1).is_err());
    }
}
