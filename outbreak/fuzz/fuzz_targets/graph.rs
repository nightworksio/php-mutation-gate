//! A PHP file's text and a graph request, as `outbreak graph reach` reads them: any bytes are read
//! without a panic, and the names a file is read to use are each fully qualified and lower-cased.
#![no_main]

use libfuzzer_sys::fuzz_target;
use outbreak_graph::{names, request};

fuzz_target!(|text: &[u8]| {
    let read = names::read(text);

    for name in read.names.iter().chain(&read.declares) {
        assert_eq!(name, &name.to_ascii_lowercase());
        assert!(!name.starts_with('\\'));
    }

    drop(request::read(text));
});
