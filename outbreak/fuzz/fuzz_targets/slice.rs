//! A whole map and the files a slice keeps: any map the slicer takes is sliced without a panic, and
//! every slice is itself a map it would vouch for and slice again, compact and of the same tests.
#![no_main]

use std::collections::HashSet;

use libfuzzer_sys::fuzz_target;
use outbreak_map::{map, slice};

fuzz_target!(|input: (&[u8], Vec<String>)| {
    let (text, files) = input;
    let Ok(slicer) = slice::slicer(text) else {
        return;
    };
    let files: HashSet<String> = files.into_iter().collect();
    let sliced = slicer.slice(&files);
    let shape = map::read(&sliced).expect("a slice is a map it vouches for");

    assert!(!shape.spaced);
    assert!(shape.files.iter().all(|entry| files.contains(&entry.path)));
    slice::slicer(&sliced).expect("a slice is a map it slices again");
});
