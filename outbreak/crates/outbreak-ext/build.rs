//! On macOS the extension leaves PHP's own symbols to the PHP that loads it,
//! as a PHP extension does; elsewhere the linker leaves them so already.

fn main() {
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("macos") {
        println!("cargo:rustc-cdylib-link-arg=-Wl,-undefined,dynamic_lookup");
    }
}
