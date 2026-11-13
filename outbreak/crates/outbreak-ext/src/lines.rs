//! The lines a stream's parent executes (ADR-0147): while recording, the
//! line of each op a user function executes, by file, as the executor the
//! extension takes over at startup hands each op over.

use std::cell::RefCell;
use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};

/// The bits of one word of a file's lines.
const WORD: u32 = u64::BITS;

static ON: AtomicBool = AtomicBool::new(false);

thread_local! {
    static RECORDING: RefCell<Recording> = RefCell::new(Recording::default());
}

/// Starts recording afresh where the extension holds the executor; whether it started.
#[must_use]
pub fn start(holds: bool) -> bool {
    RECORDING.with(|recording| *recording.borrow_mut() = Recording::default());
    ON.store(holds, Ordering::Relaxed);

    holds
}

/// Whether lines are being recorded.
#[must_use]
pub fn recording() -> bool {
    ON.load(Ordering::Relaxed)
}

/// An op of the file at this address ran at this line; the file's name is read only where the address is not the
/// last one's.
pub fn ran<'n>(file: usize, name: impl FnOnce() -> Option<&'n [u8]>, line: u32) {
    RECORDING.with(|recording| recording.borrow_mut().ran(file, name, line));
}

/// Stops recording: the lines executed since it started, in order, by file.
#[must_use]
pub fn stop() -> HashMap<String, Vec<i64>> {
    ON.store(false, Ordering::Relaxed);

    RECORDING
        .with(|recording| std::mem::take(&mut *recording.borrow_mut()))
        .lines()
}

#[derive(Default)]
struct Recording {
    last: usize,
    current: Option<usize>,
    names: HashMap<Vec<u8>, usize>,
    files: Vec<(Vec<u8>, Vec<u64>)>,
}

impl Recording {
    fn ran<'n>(&mut self, file: usize, name: impl FnOnce() -> Option<&'n [u8]>, line: u32) {
        let index = if file == self.last {
            self.current
        } else {
            let found = name().map(|name| self.indexed(name));
            self.last = file;
            self.current = found;
            found
        };

        if let Some((_, words)) = index.and_then(|index| self.files.get_mut(index)) {
            marked(words, line);
        }
    }

    fn indexed(&mut self, name: &[u8]) -> usize {
        if let Some(&index) = self.names.get(name) {
            return index;
        }

        let index = self.files.len();
        self.names.insert(name.to_vec(), index);
        self.files.push((name.to_vec(), Vec::new()));

        index
    }

    fn lines(self) -> HashMap<String, Vec<i64>> {
        self.files
            .into_iter()
            .map(|(name, words)| {
                (
                    String::from_utf8_lossy(&name).into_owned(),
                    lines_of(&words),
                )
            })
            .collect()
    }
}

fn lines_of(words: &[u64]) -> Vec<i64> {
    let mut lines = Vec::new();

    for (index, &word) in words.iter().enumerate() {
        let mut rest = word;

        while rest != 0 {
            let bit = rest.trailing_zeros();
            rest &= rest - 1;
            lines.push(
                i64::try_from(index)
                    .unwrap_or(i64::MAX)
                    .saturating_mul(i64::from(WORD))
                    + i64::from(bit),
            );
        }
    }

    lines
}

fn marked(words: &mut Vec<u64>, line: u32) {
    let at = usize::try_from(line / WORD).unwrap_or(usize::MAX);

    if at >= words.len() {
        words.resize(at.saturating_add(1), 0);
    }

    if let Some(word) = words.get_mut(at) {
        *word |= 1 << (line % WORD);
    }
}

#[cfg(test)]
mod tests {
    use std::cell::Cell;
    use std::collections::HashMap;

    use super::{Recording, ran, recording, start, stop};

    fn named<'n>(name: Option<&'n str>, reads: &Cell<usize>) -> impl FnOnce() -> Option<&'n [u8]> {
        move || {
            reads.set(reads.get() + 1);
            name.map(str::as_bytes)
        }
    }

    #[test]
    fn keeps_each_line_once_in_order_by_file() {
        let reads = Cell::new(0);
        let mut recorded = Recording::default();
        recorded.ran(1, named(Some("a.php"), &reads), 70);
        recorded.ran(1, named(Some("a.php"), &reads), 3);
        recorded.ran(2, named(Some("b.php"), &reads), 0);
        recorded.ran(3, named(None, &reads), 9);
        recorded.ran(3, named(None, &reads), 10);
        recorded.ran(4, named(Some("a.php"), &reads), 3);
        recorded.ran(4, named(Some("a.php"), &reads), 64);

        assert_eq!(reads.get(), 4);
        assert_eq!(
            recorded.lines(),
            HashMap::from([
                ("a.php".to_owned(), vec![3, 64, 70]),
                ("b.php".to_owned(), vec![0]),
            ])
        );
    }

    #[test]
    fn records_only_once_started_where_the_executor_is_held() {
        assert!(!start(false));
        assert!(!recording());
        assert!(start(true));
        assert!(recording());

        ran(1, named(Some("a.php"), &Cell::new(0)), 2);

        assert_eq!(stop(), HashMap::from([("a.php".to_owned(), vec![2])]));
        assert!(!recording());
        assert!(stop().is_empty());
    }
}
