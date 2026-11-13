//! The graph of a project's files by the names they use: each file joined to
//! every file that declares a name it uses, and every test file to each file
//! every test loads before it runs. It answers which test files reach a
//! file, which files the tests that reach a file reach, and which files a
//! file names. Where a file on the way holds a node the graph cannot place,
//! it gives no answer, and the coverage map's stands alone. A test file the
//! coverage map shows running a file the graph does not join to it is one
//! the graph is wrong of, and no answer is taken from it.

use std::cell::OnceCell;
use std::collections::{BTreeMap, BTreeSet, VecDeque};

use crate::names::FileNames;

/// A project's files and how they join.
#[derive(Debug)]
pub struct Graph {
    paths: Vec<String>,
    tests: BTreeSet<usize>,
    unplaced: Vec<Option<&'static str>>,
    reached_by: Vec<BTreeSet<usize>>,
    reaches: Vec<BTreeSet<usize>>,
    index: BTreeMap<String, usize>,
    wrong: BTreeSet<usize>,
    forwards: OnceCell<BTreeMap<usize, Option<BTreeSet<usize>>>>,
}

/// One file of the graph: its path, what it declares and names, and whether it is a test file.
pub type File = (String, FileNames, bool);

impl Graph {
    /// The graph of these files, each test file joined to each of the files every test loads.
    #[must_use]
    pub fn of(files: Vec<File>, always: &[String]) -> Self {
        let mut declared: BTreeMap<&str, Vec<usize>> = BTreeMap::new();

        for (at, (_, names, _)) in files.iter().enumerate() {
            for name in &names.declares {
                declared.entry(name.as_str()).or_default().push(at);
            }
        }

        let mut reached_by = vec![BTreeSet::new(); files.len()];
        let mut reaches = vec![BTreeSet::new(); files.len()];

        for (at, (_, names, _)) in files.iter().enumerate() {
            for name in &names.names {
                for &target in declared.get(name.as_str()).map_or(&[][..], Vec::as_slice) {
                    join(&mut reaches, &mut reached_by, at, target);
                }
            }
        }

        let index: BTreeMap<String, usize> = files
            .iter()
            .enumerate()
            .map(|(at, (path, _, _))| (path.clone(), at))
            .collect();
        let tests: BTreeSet<usize> = files
            .iter()
            .enumerate()
            .filter(|(_, (_, _, test))| *test)
            .map(|(at, _)| at)
            .collect();

        for loaded in always.iter().filter_map(|path| index.get(path)) {
            for &test in &tests {
                join(&mut reaches, &mut reached_by, test, *loaded);
            }
        }

        let unplaced = files.iter().map(|(_, names, _)| names.unplaced).collect();
        let paths = files.into_iter().map(|(path, _, _)| path).collect();

        Self {
            paths,
            tests,
            unplaced,
            reached_by,
            reaches,
            index,
            wrong: BTreeSet::new(),
            forwards: OnceCell::new(),
        }
    }

    /// The graph, each test file marked wrong where the coverage map shows it
    /// running a file of the graph that the graph does not join to it.
    #[must_use]
    pub fn checked(mut self, map: &BTreeMap<String, Vec<String>>) -> Self {
        let mut wrong = BTreeSet::new();

        for (test, files) in map {
            let Some(&at) = self.index.get(test).filter(|at| self.tests.contains(at)) else {
                continue;
            };
            let Some(Some(forward)) = self.forwards().get(&at) else {
                continue;
            };
            let missing = files
                .iter()
                .filter_map(|file| self.index.get(file))
                .any(|file| !forward.contains(file));

            if missing {
                wrong.insert(at);
            }
        }

        self.wrong = wrong;
        self
    }

    /// The test files that reach a file by the names they use, sorted, but
    /// for those the graph is wrong of; none where the file is not in the
    /// graph, or a file on the way holds a node the graph cannot place.
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
            self.named(
                seen.into_iter()
                    .filter(|at| self.tests.contains(at) && !self.wrong.contains(at)),
            ),
        )
    }

    /// Every file that is not a test file which any test file reaching a
    /// file reaches, sorted; none where the file is not in the graph, or any
    /// test file reaches a node the graph cannot place, or is one the graph
    /// is wrong of, since such a test could reach the file unseen.
    #[must_use]
    pub fn joined(&self, path: &str) -> Option<Vec<String>> {
        let target = *self.index.get(path)?;
        let mut joined = BTreeSet::new();

        for (test, forward) in self.forwards() {
            let forward = forward.as_ref().filter(|_| !self.wrong.contains(test))?;

            if forward.contains(&target) {
                joined.extend(
                    forward
                        .iter()
                        .copied()
                        .filter(|at| !self.tests.contains(at)),
                );
            }
        }

        Some(self.named(joined.into_iter()))
    }

    /// The files a file names directly, sorted; none where the file is not in the graph.
    #[must_use]
    pub fn names(&self, path: &str) -> Option<Vec<String>> {
        let at = *self.index.get(path)?;

        Some(self.named(self.reaches.get(at).into_iter().flatten().copied()))
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

    /// Each test file the graph is wrong of, by its path, sorted.
    #[must_use]
    pub fn wrong(&self) -> Vec<String> {
        self.named(self.wrong.iter().copied())
    }

    /// Every file each test file reaches, itself included, by the test
    /// file; none for one that reaches a node the graph cannot place.
    fn forwards(&self) -> &BTreeMap<usize, Option<BTreeSet<usize>>> {
        self.forwards.get_or_init(|| {
            self.tests
                .iter()
                .map(|&test| (test, self.forward(test)))
                .collect()
        })
    }

    fn forward(&self, start: usize) -> Option<BTreeSet<usize>> {
        let mut seen = BTreeSet::from([start]);
        let mut queue = VecDeque::from([start]);

        while let Some(at) = queue.pop_front() {
            if self.unplaced.get(at).copied().flatten().is_some() {
                return None;
            }

            for &to in self.reaches.get(at).into_iter().flatten() {
                if seen.insert(to) {
                    queue.push_back(to);
                }
            }
        }

        Some(seen)
    }

    fn named(&self, files: impl Iterator<Item = usize>) -> Vec<String> {
        let mut named: Vec<String> = files.filter_map(|at| self.paths.get(at).cloned()).collect();
        named.sort();
        named
    }
}

fn join(
    reaches: &mut [BTreeSet<usize>],
    reached_by: &mut [BTreeSet<usize>],
    from: usize,
    to: usize,
) {
    if from == to {
        return;
    }

    if let Some(set) = reaches.get_mut(from) {
        set.insert(to);
    }

    if let Some(set) = reached_by.get_mut(to) {
        set.insert(from);
    }
}

#[cfg(test)]
mod tests {
    use std::collections::BTreeMap;

    use super::Graph;
    use crate::names::read;

    fn graph(files: &[(&str, &str, bool)], always: &[&str]) -> Graph {
        Graph::of(
            files
                .iter()
                .map(|(path, text, test)| ((*path).to_owned(), read(text.as_bytes()), *test))
                .collect(),
            &always
                .iter()
                .map(|path| (*path).to_owned())
                .collect::<Vec<_>>(),
        )
    }

    fn owned(paths: &[&str]) -> Vec<String> {
        paths.iter().map(|path| (*path).to_owned()).collect()
    }

    const WALLET: [(&str, &str, bool); 4] = [
        (
            "src/Money.php",
            "<?php namespace App; final class Money {}",
            false,
        ),
        (
            "src/Wallet.php",
            "<?php namespace App; final class Wallet { public function add(Money $m) {} }",
            false,
        ),
        (
            "tests/WalletTest.php",
            "<?php use App\\Wallet; it('adds', fn () => new Wallet());",
            true,
        ),
        (
            "tests/OtherTest.php",
            "<?php it('is alone', fn () => true);",
            true,
        ),
    ];

    #[test]
    fn finds_the_tests_that_reach_a_file_through_others() {
        let found = graph(&WALLET, &[]);

        assert_eq!(
            found.reaching("src/Money.php"),
            Some(owned(&["tests/WalletTest.php"]))
        );
        assert_eq!(found.reaching("src/Missing.php"), None);
    }

    #[test]
    fn joins_a_file_to_what_the_tests_that_reach_it_reach() {
        let found = graph(&WALLET, &[]);

        assert_eq!(
            found.joined("src/Money.php"),
            Some(owned(&["src/Money.php", "src/Wallet.php"]))
        );
        assert_eq!(found.joined("tests/OtherTest.php"), Some(vec![]));
        assert_eq!(
            found.names("src/Wallet.php"),
            Some(owned(&["src/Money.php"]))
        );
    }

    #[test]
    fn joins_every_test_to_what_every_test_loads() {
        let found = graph(
            &[
                (
                    "src/Clock.php",
                    "<?php namespace App; final class Clock {}",
                    false,
                ),
                (
                    "tests/Pest.php",
                    "<?php use App\\Clock; uses(Clock::class);",
                    false,
                ),
                ("tests/ATest.php", "<?php it('runs', fn () => true);", true),
            ],
            &["tests/Pest.php"],
        );

        assert_eq!(
            found.reaching("src/Clock.php"),
            Some(owned(&["tests/ATest.php"]))
        );
    }

    #[test]
    fn gives_no_answer_through_a_node_it_cannot_place() {
        let found = graph(
            &[
                (
                    "src/Money.php",
                    "<?php namespace App; final class Money {}",
                    false,
                ),
                (
                    "src/Bag.php",
                    "<?php namespace App; final class Bag { public function __get($n) { return new Money(); } }",
                    false,
                ),
                (
                    "tests/BagTest.php",
                    "<?php use App\\Bag; it('holds', fn () => new Bag());",
                    true,
                ),
            ],
            &[],
        );

        assert_eq!(found.reaching("src/Money.php"), None);
        assert_eq!(found.joined("src/Money.php"), None);
        assert_eq!(
            found.unplaced(),
            vec![(
                "src/Bag.php".to_owned(),
                "a magic method that answers any name"
            )]
        );
    }

    #[test]
    fn takes_no_answer_from_a_test_the_map_shows_it_wrong_of() {
        let map = BTreeMap::from([("tests/OtherTest.php".to_owned(), owned(&["src/Money.php"]))]);
        let found = graph(&WALLET, &[]).checked(&map);

        assert_eq!(found.wrong(), owned(&["tests/OtherTest.php"]));
        assert_eq!(
            found.reaching("src/Wallet.php"),
            Some(owned(&["tests/WalletTest.php"]))
        );
        assert_eq!(found.joined("src/Money.php"), None);
    }

    #[test]
    fn checks_only_the_tests_it_can_follow() {
        let map = BTreeMap::from([
            ("tests/Gone.php".to_owned(), owned(&["src/Money.php"])),
            ("src/Wallet.php".to_owned(), owned(&["src/Money.php"])),
        ]);

        assert_eq!(graph(&WALLET, &[]).checked(&map).wrong(), owned(&[]));

        let unfollowed = graph(
            &[
                (
                    "src/Bag.php",
                    "<?php namespace App; final class Bag { public function __get($n) {} }",
                    false,
                ),
                (
                    "tests/BagTest.php",
                    "<?php use App\\Bag; it('holds', fn () => new Bag());",
                    true,
                ),
            ],
            &[],
        )
        .checked(&BTreeMap::from([(
            "tests/BagTest.php".to_owned(),
            owned(&["src/Bag.php"]),
        )]));

        assert_eq!(unfollowed.wrong(), owned(&[]));
    }
}
