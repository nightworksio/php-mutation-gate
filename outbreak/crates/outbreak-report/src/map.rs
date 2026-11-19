//! The gate's coverage map of a report, built as the gate's PHP builds it,
//! and written as it writes it (`MapText`).
//!
//! - Each test is placed where a line first names it, file by file and line
//!   by line (`Tally`); then each test the report's results time, and each the
//!   run timed beside it that the results do not, after those, in their order
//!   (`CoverageMap::timedEach()`, `TimedTests::onto()`).
//! - Where an admission is asked for, the tests leaving drop off the lines of
//!   each file they do not stay on, and the tests are placed anew where the
//!   map's lines, its covered ones first, name them (`Admission`).
//! - Its file lists every test with its seconds where it was timed, and each
//!   file's lines, the covered ones first, by their tests' places.

use std::collections::{HashMap, HashSet};

use outbreak_map::json::Refused;

use crate::report::{Report, is_whole_number};
use crate::request::Asked;
use crate::text;

/// No place yet.
const UNPLACED: u32 = u32::MAX;

/// A test's seconds: a float the report holds, or the gate's own JSON of one.
#[derive(Debug, Clone, Copy, PartialEq)]
enum Seconds<'a> {
    Float(f64),
    Text(&'a [u8]),
}

/// A map: every test in its order, and each file's lines in the order the map knows them.
#[derive(Debug, Clone, PartialEq)]
pub struct Map<'a> {
    tests: Vec<&'a str>,
    places: HashMap<&'a str, u32>,
    files: Vec<File>,
    seconds: HashMap<&'a str, Seconds<'a>>,
}

/// A file of a map, and its lines, each with its tests' places.
#[derive(Debug, Clone, PartialEq, Eq)]
struct File {
    path: String,
    lines: Vec<(i64, Vec<u32>)>,
}

impl<'a> Map<'a> {
    /// The map of a report, each file under the path the closure gives it,
    /// knowing each test the run timed beside it.
    ///
    /// # Errors
    ///
    /// Refuses where the closure gives a file no path.
    pub fn of(
        report: &Report<'a>,
        asked: &'a Asked<'a>,
        mut path: impl FnMut(String) -> Result<String, Refused>,
    ) -> Result<Self, Refused> {
        let mut map = Self {
            tests: Vec::new(),
            places: HashMap::new(),
            files: Vec::with_capacity(report.files.len()),
            seconds: HashMap::new(),
        };
        let mut by_rank = vec![UNPLACED; report.ids.len()];
        let base = report.base.trim_end_matches('/');

        for file in &report.files {
            let mut lines = Vec::with_capacity(file.lines.len());

            for line in &file.lines {
                let places = line
                    .tests
                    .iter()
                    .map(|rank| map.placed(&mut by_rank, report, *rank))
                    .collect();
                lines.push((line.number, places));
            }

            map.files.push(File {
                path: path(format!("{base}/{}", file.name))?,
                lines,
            });
        }

        for (test, seconds) in &report.times {
            map.timed(test, Seconds::Float(*seconds));
        }

        let reported: HashSet<&str> = report.times.iter().map(|(test, _)| *test).collect();

        for (test, at) in &asked.timed {
            if !reported.contains(test.as_str()) {
                map.timed(
                    test,
                    Seconds::Text(asked.text.get(at.clone()).unwrap_or_default()),
                );
            }
        }

        Ok(map)
    }

    /// The place of a test by its place in the report's list, placed where it is first named.
    fn placed(&mut self, by_rank: &mut [u32], report: &Report<'a>, rank: u32) -> u32 {
        let index = usize::try_from(rank).unwrap_or(usize::MAX);
        let placed = by_rank.get(index).copied().unwrap_or(UNPLACED);
        let place = if placed == UNPLACED {
            self.known(report.ids.get(index).copied().unwrap_or_default())
        } else {
            placed
        };
        by_rank
            .get_mut(index)
            .into_iter()
            .for_each(|slot| *slot = place);

        place
    }

    /// The place of a test, after every test known where it is not one.
    fn known(&mut self, test: &'a str) -> u32 {
        let next = u32::try_from(self.tests.len()).unwrap_or(UNPLACED);

        *self.places.entry(test).or_insert_with(|| {
            self.tests.push(test);
            next
        })
    }

    fn timed(&mut self, test: &'a str, seconds: Seconds<'a>) {
        self.seconds.insert(test, seconds);
        self.known(test);
    }

    /// The map less the tests leaving, but on the lines of each file a test
    /// stays on, the tests placed anew; each test that stays keeps its time.
    #[must_use]
    pub fn admitted(&self, leaving: &HashMap<String, HashSet<String>>) -> Self {
        let leaves: Vec<bool> = self
            .tests
            .iter()
            .map(|test| leaving.contains_key(*test))
            .collect();
        let dropped = self.dropped(&leaves, leaving);
        let mut admitted = Self {
            tests: Vec::new(),
            places: HashMap::new(),
            files: Vec::new(),
            seconds: HashMap::new(),
        };
        let mut anew = vec![UNPLACED; self.tests.len()];
        let mut order = Vec::new();
        let mut lines: HashMap<usize, Vec<(i64, Vec<u32>)>> = HashMap::new();

        for (at, _, (number, places)) in self.placed_lines() {
            let leaving_here = dropped.get(at);
            let mut by = Vec::with_capacity(places.len());

            for place in places {
                if leaving_here.is_some_and(|dropped| dropped.contains(place)) {
                    continue;
                }

                let index = usize::try_from(*place).unwrap_or(usize::MAX);
                let placed = anew.get(index).copied().unwrap_or(UNPLACED);
                let slot = if placed == UNPLACED {
                    admitted.known(self.tests.get(index).copied().unwrap_or_default())
                } else {
                    placed
                };
                anew.get_mut(index)
                    .into_iter()
                    .for_each(|anew| *anew = slot);
                by.push(slot);
            }

            if !lines.contains_key(&at) {
                order.push(at);
            }

            lines.entry(at).or_default().push((*number, by));
        }

        admitted.files = order
            .iter()
            .map(|at| File {
                path: self
                    .files
                    .get(*at)
                    .map(|file| file.path.clone())
                    .unwrap_or_default(),
                lines: lines.remove(at).unwrap_or_default(),
            })
            .collect();

        for (place, test) in self.tests.iter().enumerate() {
            let stays = !leaves.get(place).copied().unwrap_or_default()
                || anew.get(place).is_some_and(|slot| *slot != UNPLACED);

            if let Some(seconds) = self.seconds.get(test).filter(|_| stays) {
                admitted.timed(test, *seconds);
            }
        }

        admitted
    }

    /// The places of the tests that leave the lines of each file, by the file's place.
    fn dropped(
        &self,
        leaves: &[bool],
        leaving: &HashMap<String, HashSet<String>>,
    ) -> Vec<HashSet<u32>> {
        self.files
            .iter()
            .map(|file| {
                let mut dropped = HashSet::new();

                for (_, places) in &file.lines {
                    for place in places {
                        let index = usize::try_from(*place).unwrap_or(usize::MAX);
                        let stays_on = self
                            .tests
                            .get(index)
                            .and_then(|test| leaving.get(*test))
                            .is_some_and(|files| files.contains(&file.path));

                        if leaves.get(index).copied().unwrap_or_default() && !stays_on {
                            dropped.insert(*place);
                        }
                    }
                }

                dropped
            })
            .collect()
    }

    /// Every line, each with its file and the file's place: the covered ones,
    /// file by file, then those no test ran, as `LineTests::placedLines()`
    /// lists them.
    fn placed_lines(&self) -> impl Iterator<Item = (usize, &File, &(i64, Vec<u32>))> {
        [false, true].into_iter().flat_map(move |missed| {
            self.files.iter().enumerate().flat_map(move |(at, file)| {
                file.lines
                    .iter()
                    .filter(move |(_, places)| places.is_empty() == missed)
                    .map(move |line| (at, file, line))
            })
        })
    }

    /// The map's file, as the gate writes it with where it was measured and its entries' keys.
    #[must_use]
    pub fn written(&self, asked: &Asked<'_>) -> Vec<u8> {
        let mut out = Vec::with_capacity(
            self.files
                .iter()
                .map(|file| file.lines.len())
                .sum::<usize>()
                * 64,
        );
        let raw =
            |range: &std::ops::Range<usize>| asked.text.get(range.clone()).unwrap_or_default();
        out.extend_from_slice(b"{\"format\":1");

        for member in &asked.measured {
            out.push(b',');
            out.extend_from_slice(raw(member));
        }

        out.extend_from_slice(b",\"tests\":[");
        self.write_tests(&mut out);
        out.extend_from_slice(b"],\"files\":{");
        self.write_files(&mut out);
        out.push(b'}');

        if !asked.loads.is_empty() {
            out.extend_from_slice(b",\"loads\":[");

            for (at, process) in asked.loads.iter().enumerate() {
                out.extend_from_slice(if at == 0 { b"[" } else { b",[" });
                let mut first = true;

                for load in process {
                    if let Some(place) = self.places.get(load.test.as_str()) {
                        out.extend_from_slice(if first { b"[" } else { b",[" });
                        out.extend_from_slice(place.to_string().as_bytes());
                        out.push(b',');
                        out.extend_from_slice(raw(&load.packages));
                        out.push(b',');
                        out.extend_from_slice(raw(&load.started));
                        out.push(b']');
                        first = false;
                    }
                }

                out.push(b']');
            }

            out.push(b']');
        }

        if let Some(keys) = &asked.keys {
            out.extend_from_slice(b",\"keys\":");
            out.extend_from_slice(raw(keys));
        }

        out.push(b'}');

        out
    }

    fn write_tests(&self, out: &mut Vec<u8>) {
        for (at, test) in self.tests.iter().enumerate() {
            out.extend_from_slice(if at == 0 { b"{\"id\":" } else { b",{\"id\":" });
            text::string(out, test);

            match self.seconds.get(test) {
                Some(Seconds::Float(seconds)) => {
                    out.extend_from_slice(b",\"seconds\":");
                    text::float(out, *seconds);
                }
                Some(Seconds::Text(seconds)) => {
                    out.extend_from_slice(b",\"seconds\":");
                    out.extend_from_slice(seconds);
                }
                None => {}
            }

            out.push(b'}');
        }
    }

    /// Each file with its lines, in the order the gate's file lists them: a
    /// file where its first line comes, the covered lines first.
    fn write_files(&self, out: &mut Vec<u8>) {
        let mut order: Vec<usize> = Vec::with_capacity(self.files.len());
        let mut lines: HashMap<usize, Vec<&(i64, Vec<u32>)>> = HashMap::new();

        for (at, _, line) in self.placed_lines() {
            if !lines.contains_key(&at) {
                order.push(at);
            }

            lines.entry(at).or_default().push(line);
        }

        for (written, at) in order.iter().enumerate() {
            let path = self.files.get(*at).map_or("", |file| file.path.as_str());
            let held = lines.get(at).map_or(&[][..], Vec::as_slice);

            if written > 0 {
                out.push(b',');
            }

            text::string(out, path);
            out.extend_from_slice(b":{");

            for (number, (line, places)) in held.iter().enumerate() {
                if number > 0 {
                    out.push(b',');
                }

                out.push(b'"');
                out.extend_from_slice(line.to_string().as_bytes());
                out.extend_from_slice(b"\":[");

                for (item, place) in places.iter().enumerate() {
                    if item > 0 {
                        out.push(b',');
                    }

                    out.extend_from_slice(place.to_string().as_bytes());
                }

                out.push(b']');
            }

            out.push(b'}');
        }
    }

    /// The map's outline, as JSON: each file as the report names it, and
    /// each test with the places of the files it ran a line of.
    #[must_use]
    pub fn outline(&self) -> Vec<u8> {
        let mut ran: Vec<Vec<usize>> = vec![Vec::new(); self.tests.len()];

        for (at, file) in self.files.iter().enumerate() {
            for (_, places) in &file.lines {
                for place in places {
                    let index = usize::try_from(*place).unwrap_or(usize::MAX);

                    if let Some(files) =
                        ran.get_mut(index).filter(|files| files.last() != Some(&at))
                    {
                        files.push(at);
                    }
                }
            }
        }

        let mut out = b"{\"files\":[".to_vec();

        for (at, file) in self.files.iter().enumerate() {
            if at > 0 {
                out.push(b',');
            }

            text::string(&mut out, &file.path);
        }

        out.extend_from_slice(b"],\"tests\":[");

        for (at, (test, files)) in self.tests.iter().zip(&ran).enumerate() {
            out.extend_from_slice(if at == 0 { b"[" } else { b",[" });
            text::string(&mut out, test);
            out.extend_from_slice(b",[");
            let listed: Vec<String> = files.iter().map(ToString::to_string).collect();
            out.extend_from_slice(listed.join(",").as_bytes());
            out.extend_from_slice(b"]]");
        }

        out.extend_from_slice(b"]}");

        out
    }
}

/// Each file's path in the project, as the request gives it: one for every
/// file, two files never at one, and none PHP keys as a whole number.
///
/// # Errors
///
/// Refuses a file the request gives no path, or a path it cannot be at.
pub fn paths<'r>(asked: &'r Asked<'_>) -> impl FnMut(String) -> Result<String, Refused> + 'r {
    let mut taken = HashSet::new();

    move |file| {
        let path = asked.paths.get(&file).ok_or(Refused {
            at: 0,
            expected: "a path for each file",
        })?;

        if is_whole_number(path) || !taken.insert(path.clone()) {
            return Err(Refused {
                at: 0,
                expected: "a path no other file is at, PHP keeps as text",
            });
        }

        Ok(path.clone())
    }
}
