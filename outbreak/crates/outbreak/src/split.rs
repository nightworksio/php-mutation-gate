//! `outbreak map split`: the whole map a request names, read once, and each
//! slice it asks for written as the gate writes it, gzipped, the slices packed
//! side by side on as many threads as it is given. Each slice is written beside
//! its place and moved into it, so none is ever seen half written.

use std::fs;
use std::io::{Read, Write};
use std::num::NonZero;
use std::path::Path;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::mpsc;
use std::thread;

use flate2::Compression;
use flate2::bufread::GzDecoder;
use flate2::write::GzEncoder;
use outbreak_map::request::{self, Slice};
use outbreak_map::slice::{self, Slicer};

use crate::{CONTRACT, Failure, said};

/// The most bytes a whole map inflates to before it is declined: a gibibyte, as the gate reads
/// a handed map in a process with no memory limit.
pub const MOST_INFLATED: u64 = 1 << 30;

/// What a slice is written as before it is moved into its place.
const PARTIAL: &str = "outbreak-partial";

/// Each slice a request asks for, written; or why the whole map is declined.
pub fn run(input: &[u8], threads: NonZero<usize>) -> Result<String, Failure> {
    let asked = request::read(input).map_err(|refused| {
        Failure::Usage(format!("the split request is not one: {}", said(&refused)))
    })?;
    let packed = fs::read(&asked.whole)
        .map_err(|error| Failure::Io(format!("cannot read {}: {error}", asked.whole)))?;
    let Some(text) = inflated(&packed, MOST_INFLATED) else {
        return Ok(declined(
            "the whole map is not one gzip stream within a gibibyte",
        ));
    };
    let slicer = match slice::slicer(&text) {
        Ok(slicer) => slicer,
        Err(refused) => return Ok(declined(&said(&refused))),
    };
    let written = written(&slicer, &asked.slices, threads)?;

    Ok(format!(r#"{{"outbreak":{CONTRACT},"written":{written}}}"#))
}

fn declined(why: &str) -> String {
    format!(r#"{{"outbreak":{CONTRACT},"declined":"{why}"}}"#)
}

/// The text a gzip stream holds, where it is one whole stream, nothing after it, inflating to no
/// more than `most` bytes.
fn inflated(packed: &[u8], most: u64) -> Option<Vec<u8>> {
    let mut decoder = GzDecoder::new(packed);
    let mut text = Vec::new();
    let read = (&mut decoder).take(most + 1).read_to_end(&mut text).ok()?;
    let rest = decoder.into_inner();

    (u64::try_from(read).is_ok_and(|read| read <= most) && rest.is_empty()).then_some(text)
}

/// Every slice written, on as many threads as there are slices or threads, whichever is fewer. A
/// thread that stops early stops the whole run with it.
fn written(
    whole: &Slicer<'_>,
    slices: &[Slice],
    threads: NonZero<usize>,
) -> Result<usize, Failure> {
    let next = &AtomicUsize::new(0);
    let (failed, failures) = mpsc::channel();

    thread::scope(|scope| {
        for _ in 0..threads.get().min(slices.len()) {
            let failed = failed.clone();
            scope.spawn(move || {
                for failure in work(whole, slices, next) {
                    drop(failed.send(failure));
                }
            });
        }
    });
    drop(failed);

    match failures.into_iter().next() {
        Some(failure) => Err(failure),
        None => Ok(slices.len()),
    }
}

/// The slices one thread takes in turn until none is left, with why any could not be written.
fn work(whole: &Slicer<'_>, slices: &[Slice], next: &AtomicUsize) -> Vec<Failure> {
    let mut failures = Vec::new();

    while let Some(slice) = slices.get(next.fetch_add(1, Ordering::Relaxed)) {
        if let Err(failure) = write(whole, slice) {
            failures.push(failure);
        }
    }

    failures
}

fn write(whole: &Slicer<'_>, slice: &Slice) -> Result<(), Failure> {
    let failed =
        |error: std::io::Error| Failure::Io(format!("cannot write {}: {error}", slice.into));
    let into = Path::new(&slice.into);
    let partial = into.with_extension(PARTIAL);

    into.parent()
        .map_or(Ok(()), fs::create_dir_all)
        .map_err(failed)?;

    let mut encoder = GzEncoder::new(Vec::new(), Compression::default());
    encoder
        .write_all(&whole.slice(&slice.files))
        .map_err(failed)?;
    fs::write(&partial, encoder.finish().map_err(failed)?).map_err(failed)?;
    fs::rename(&partial, into).map_err(failed)
}

#[cfg(test)]
mod tests {
    use std::fs;
    use std::io::{Read, Write};
    use std::num::NonZero;
    use std::path::{Path, PathBuf};

    use flate2::Compression;
    use flate2::read::GzDecoder;
    use flate2::write::GzEncoder;

    use super::{MOST_INFLATED, inflated, run};
    use crate::Failure;

    const MAP: &str =
        r#"{"format":1,"tests":[{"id":"a"}],"files":{"src/A.php":{"1":[0]},"src/B.php":{"2":[]}}}"#;

    struct Scratch(PathBuf);

    impl Scratch {
        fn new(name: &str) -> Self {
            let path =
                std::env::temp_dir().join(format!("outbreak-split-{name}-{}", std::process::id()));
            drop(fs::remove_dir_all(&path));
            fs::create_dir_all(&path).unwrap();

            Self(path)
        }

        fn at(&self, name: &str) -> String {
            self.0.join(name).to_string_lossy().into_owned()
        }
    }

    impl Drop for Scratch {
        fn drop(&mut self) {
            drop(fs::remove_dir_all(&self.0));
        }
    }

    fn packed(text: &[u8]) -> Vec<u8> {
        let mut encoder = GzEncoder::new(Vec::new(), Compression::default());
        encoder.write_all(text).unwrap();
        encoder.finish().unwrap()
    }

    fn unpacked(path: &str) -> String {
        let mut text = String::new();
        GzDecoder::new(fs::read(path).unwrap().as_slice())
            .read_to_string(&mut text)
            .unwrap();

        text
    }

    fn request(whole: &str, slices: &[(String, &[&str])]) -> Vec<u8> {
        let slices: Vec<String> = slices
            .iter()
            .map(|(into, files)| {
                let files: Vec<String> = files.iter().map(|file| format!("\"{file}\"")).collect();
                format!(r#"{{"into":"{into}","files":[{}]}}"#, files.join(","))
            })
            .collect();

        format!(r#"{{"whole":"{whole}","slices":[{}]}}"#, slices.join(",")).into_bytes()
    }

    fn two() -> NonZero<usize> {
        NonZero::new(2).unwrap()
    }

    #[test]
    fn writes_each_slice_as_the_gate_writes_it() {
        let scratch = Scratch::new("writes");
        let whole = scratch.at("map.json.gz");
        fs::write(&whole, packed(MAP.as_bytes())).unwrap();
        let (first, second, third) = (
            scratch.at("shard-1/map.json.gz"),
            scratch.at("shard-2/map.json.gz"),
            scratch.at("verdict/map.json.gz"),
        );
        let asked = request(
            &whole,
            &[
                (first.clone(), &["src/A.php"]),
                (second.clone(), &[]),
                (third.clone(), &["src/A.php", "src/B.php"]),
            ],
        );

        assert_eq!(run(&asked, two()).unwrap(), r#"{"outbreak":1,"written":3}"#);
        assert_eq!(
            unpacked(&first),
            r#"{"format":1,"tests":[{"id":"a"}],"files":{"src/A.php":{"1":[0]}}}"#
        );
        assert_eq!(
            unpacked(&second),
            r#"{"format":1,"tests":[{"id":"a"}],"files":{}}"#
        );
        assert_eq!(
            unpacked(&third),
            r#"{"format":1,"tests":[{"id":"a"}],"files":{"src/A.php":{"1":[0]},"src/B.php":{"2":[]}}}"#
        );
        assert!(!Path::new(&scratch.at("shard-1/map.json.outbreak-partial")).exists());
    }

    #[test]
    fn writes_nothing_where_no_slice_is_asked_for() {
        let scratch = Scratch::new("none");
        let whole = scratch.at("map.json.gz");
        fs::write(&whole, packed(MAP.as_bytes())).unwrap();

        assert_eq!(
            run(&request(&whole, &[]), two()).unwrap(),
            r#"{"outbreak":1,"written":0}"#
        );
    }

    #[test]
    fn declines_a_whole_map_the_gate_did_not_write() {
        let scratch = Scratch::new("declines");
        let whole = scratch.at("map.json.gz");
        fs::write(&whole, packed(b"{\"format\":1, \"tests\":[],\"files\":{}}")).unwrap();

        assert_eq!(
            run(&request(&whole, &[(scratch.at("1.gz"), &[])]), two()).unwrap(),
            r#"{"outbreak":1,"declined":"expected a compact map, as the gate writes one at byte 0"}"#
        );

        fs::write(&whole, b"not gzip").unwrap();
        assert_eq!(
            run(&request(&whole, &[]), two()).unwrap(),
            r#"{"outbreak":1,"declined":"the whole map is not one gzip stream within a gibibyte"}"#
        );
    }

    #[test]
    fn inflates_one_whole_stream_alone_within_the_limit() {
        assert_eq!(inflated(&packed(b"text"), 4).unwrap(), b"text");
        assert!(inflated(&packed(b"texts"), 4).is_none());
        assert!(inflated(&[packed(b"one"), packed(b"two")].concat(), 10).is_none());
        assert!(inflated(&packed(b"text")[..10], 10).is_none());
        assert_eq!(MOST_INFLATED, 1_073_741_824);
    }

    #[test]
    fn fails_on_a_request_it_cannot_read_or_a_file_it_cannot_reach() {
        let scratch = Scratch::new("fails");

        assert!(matches!(run(b"[]", two()).unwrap_err(), Failure::Usage(_)));
        assert!(matches!(
            run(&request(&scratch.at("absent.gz"), &[]), two()).unwrap_err(),
            Failure::Io(why) if why.starts_with("cannot read ")
        ));

        let whole = scratch.at("map.json.gz");
        fs::write(&whole, packed(MAP.as_bytes())).unwrap();
        fs::write(scratch.at("blocking"), b"a file").unwrap();
        let below_a_file = scratch.at("blocking/shard/map.json.gz");
        let in_place_of_a_directory = scratch.at("taken");
        fs::create_dir_all(format!("{in_place_of_a_directory}/map.json.gz")).unwrap();

        assert!(matches!(
            run(&request(&whole, &[(below_a_file, &[])]), two()).unwrap_err(),
            Failure::Io(why) if why.starts_with("cannot write ")
        ));
        assert!(matches!(
            run(
                &request(&whole, &[(format!("{in_place_of_a_directory}/map.json.gz"), &[])]),
                two()
            )
            .unwrap_err(),
            Failure::Io(why) if why.starts_with("cannot write ")
        ));
    }
}
