//! The graph of a project's files by the names they use: each file joined to
//! every file that declares a name it uses, and asked which test files reach
//! a file. Where a file on the way holds a node the graph cannot place, it
//! gives no answer, and the coverage map's stands alone.

use std::collections::{BTreeMap, BTreeSet, VecDeque};

use crate::names::FileNames;

/// A project's files and how they join.
#[derive(Debug)]
pub struct Graph {
    paths: Vec<String>,
    tests: BTreeSet<usize>,
    unplaced: Vec<Option<&'static str>>,
    reached_by: Vec<BTreeSet<usize>>,
    index: BTreeMap<String, usize>,
}

impl Graph {
    /// The graph of these files, each by its path, what it declares and names, and whether it is a test file.
    #[must_use]
    pub fn of(files: Vec<(String, FileNames, bool)>) -> Self {
        let mut declared: BTreeMap<String, Vec<usize>> = BTreeMap::new();

        for (at, (_, names, _)) in files.iter().enumerate() {
            for name in &names.declares {
                declared.entry(name.clone()).or_default().push(at);
            }
        }

        let mut reached_by = vec![BTreeSet::new(); files.len()];

        for (at, (_, names, _)) in files.iter().enumerate() {
            for name in &names.names {
                for &target in declared.get(name).map_or(&[][..], Vec::as_slice) {
                    if target != at
                        && let Some(set) = reached_by.get_mut(target)
                    {
                        set.insert(at);
                    }
                }
            }
        }

        let index = files.iter().enumerate().map(|(at, (path, _, _))| (path.clone(), at)).collect();
        let tests = files.iter().enumerate().filter(|(_, (_, _, test))| *test).map(|(at, _)| at).collect();
        let unplaced = files.iter().map(|(_, names, _)| names.unplaced).collect();
        let paths = files.into_iter().map(|(path, _, _)| path).collect();

        Self { paths, tests, unplaced, reached_by, index }
    }

    /// The test files that reach a file by the names they use, sorted; none
    /// where the file is not in the graph, or a file on the way holds a node
    /// the graph cannot place.
    #[must_use]
    pub fn reaching(&self, path: &str) -> Option<Vec<String>> {
        let start = *self.index.get(path)?;
        let mut seen = BTreeSet::from([start]);
        let mut queue = VecDeque::from([start]);

        while let Some(at) = queue.pop_front() {
            if self.unplaced.get(at).copied().flatten().is_some() {
                return None;
            }

            for &from in self.reached_by.get(at).into_iter().flatten() {
                if seen.insert(from) {
                    queue.push_back(from);
                }
            }
        }

        Some(
            seen.into_iter()
                .filter(|at| self.tests.contains(at))
                .filter_map(|at| self.paths.get(at).cloned())
                .collect(),
        )
    }

    /// Each file that holds a node the graph cannot place, with why, by its path.
    #[must_use]
    pub fn unplaced(&self) -> Vec<(String, &'static str)> {
        self.paths
            .iter()
            .zip(&self.unplaced)
            .filter_map(|(path, why)| why.map(|why| (path.clone(), why)))
            .collect()
    }
}

#[cfg(test)]
mod tests {
    use super::Graph;
    use crate::names::read;

    fn graph(files: &[(&str, &str, bool)]) -> Graph {
        Graph::of(
            files
                .iter()
                .map(|(path, text, test)| ((*path).to_owned(), read(text.as_bytes()), *test))
                .collect(),
        )
    }

    #[test]
    fn finds_the_tests_that_reach_a_file_through_others() {
        let found = graph(&[
            ("src/Money.php", "<?php namespace App; final class Money {}", false),
            ("src/Wallet.php", "<?php namespace App; final class Wallet { public function add(Money $m) {} }", false),
            ("tests/WalletTest.php", "<?php use App\\Wallet; it('adds', fn () => new Wallet());", true),
            ("tests/OtherTest.php", "<?php it('is alone', fn () => true);", true),
        ]);

        assert_eq!(found.reaching("src/Money.php"), Some(vec!["tests/WalletTest.php".to_owned()]));
        assert_eq!(found.reaching("src/Missing.php"), None);
    }

    #[test]
    fn gives_no_answer_through_a_node_it_cannot_place() {
        let found = graph(&[
            ("src/Money.php", "<?php namespace App; final class Money {}", false),
            ("src/Bag.php", "<?php namespace App; final class Bag { public function __get($n) { return new Money(); } }", false),
            ("tests/BagTest.php", "<?php use App\\Bag; it('holds', fn () => new Bag());", true),
        ]);

        assert_eq!(found.reaching("src/Money.php"), None);
        assert_eq!(found.unplaced(), vec![("src/Bag.php".to_owned(), "a magic method that answers any name")]);
    }
}
