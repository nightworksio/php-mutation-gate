//! php-code-coverage's PHP report, as `outbreak report convert` and `outbreak report outline` read one: any bytes
//! are read without a panic, and the map and the outline of a report it reads are each one JSON text, the map one the
//! gate vouches for where no test took less than no time.
#![no_main]

use libfuzzer_sys::fuzz_target;
use outbreak_map::json::Cursor;
use outbreak_map::map;
use outbreak_report::{convert, outline, report, request, text};

fn is_json(text: &[u8]) -> bool {
    Cursor::over(text)
        .and_then(|mut json| {
            json.skip()?;
            json.end()
        })
        .is_ok()
}

fuzz_target!(|bytes: &[u8]| {
    let Ok(read) = report::read(bytes) else {
        return;
    };
    let base = read.base.trim_end_matches('/');
    let mut asked = br#"{"report":"r","format":4,"paths":{"#.to_vec();

    for (at, file) in read.files.iter().enumerate() {
        if at > 0 {
            asked.push(b',');
        }

        text::string(&mut asked, &format!("{base}/{}", file.name));
        asked.extend_from_slice(format!(r#":"p{at}""#).as_bytes());
    }

    asked.extend_from_slice(b"}}");
    let asked = request::read(&asked).expect("a request the fuzzer writes is one");
    let written =
        convert(bytes, &asked).expect("a report it reads converts with a path for each file");

    assert!(is_json(&written));
    assert!(is_json(
        &outline(bytes, &asked).expect("a report it reads outlines")
    ));

    if read
        .times
        .iter()
        .all(|(_, seconds)| seconds.is_sign_positive())
    {
        assert!(
            map::read(&written).is_ok(),
            "{}",
            String::from_utf8_lossy(&written)
        );
    }
});
