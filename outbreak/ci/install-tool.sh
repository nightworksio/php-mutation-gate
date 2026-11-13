#!/usr/bin/env bash
# Installs one of Outbreak's CI tools into ~/.cargo/bin from its release, pinned
# by version and by the SHA-256 of the asset, the pins herald's CI holds
# (ADR-0118). Where a release lists checksums, the pin is the one it lists;
# where it lists none, the one first downloaded. cargo-mutants and cargo-fuzz
# release no build for arm64 Linux, so their jobs run on x86_64.
set -euo pipefail

tool="${1:?usage: install-tool.sh <cargo-nextest|cargo-llvm-cov|cargo-deny|cargo-audit|cargo-mutants|cargo-fuzz>}"
arch="$(uname -m)"

# The pin of this machine's build of a tool, from its x86_64 and its aarch64 pin.
pinned() {
  if [[ "$arch" == x86_64 ]]; then
    echo "$1"
  elif [[ "$arch" == aarch64 && -n "$2" ]]; then
    echo "$2"
  else
    echo "no pin for ${tool} on ${arch}" >&2
    exit 2
  fi
}

case "$tool" in
  cargo-nextest)
    version="0.9.150"
    url="https://github.com/nextest-rs/nextest/releases/download/cargo-nextest-${version}/cargo-nextest-${version}-${arch}-unknown-linux-gnu.tar.gz"
    sha256="$(pinned b0959bc64232974224cf9fb22c915845398c0e8668fa7831eb5b43fb1eef781f 9164568e9840c6509177086828ac7d3178c2d8feb0658b4d2bff62fa620e98c8)"
    member="cargo-nextest"
    ;;
  cargo-llvm-cov)
    version="0.9.1"
    url="https://github.com/taiki-e/cargo-llvm-cov/releases/download/v${version}/cargo-llvm-cov-${arch}-unknown-linux-gnu.tar.gz"
    sha256="$(pinned b3f68e625481fed9b16444174f3fa5ebcdbde4a1878803a35eabe2dcefcdc41a abf5f13c1520f8756d2192bfaaeadb0208f5b7eaa8cadc15bf847befa42b7360)"
    member="cargo-llvm-cov"
    ;;
  cargo-deny)
    version="0.20.2"
    url="https://github.com/EmbarkStudios/cargo-deny/releases/download/${version}/cargo-deny-${version}-${arch}-unknown-linux-musl.tar.gz"
    sha256="$(pinned 9f12ed4c49936e09b48bf862b595cde2fe64fcbd9d74dfacac6131ca824c8d5f 995c82be0defc7a025cae49a2aa2644ce8245c9a3318fc4103907c6a285e8c7d)"
    member="cargo-deny-${version}-${arch}-unknown-linux-musl/cargo-deny"
    ;;
  cargo-audit)
    version="0.22.2"
    url="https://github.com/rustsec/rustsec/releases/download/cargo-audit%2Fv${version}/cargo-audit-${arch}-unknown-linux-gnu-v${version}.tgz"
    sha256="$(pinned ab28a1bdb54db4d5d8ad5981cf1f959410370b3d28250dbd35f6a44248620e39 c6603814ddaa45e51263dafd31c0ac98808f688d26f7395804f9670b0fd599dd)"
    member="cargo-audit-${arch}-unknown-linux-gnu-v${version}/cargo-audit"
    ;;
  cargo-mutants)
    version="27.1.0"
    url="https://github.com/sourcefrog/cargo-mutants/releases/download/v${version}/cargo-mutants-x86_64-unknown-linux-gnu.tar.gz"
    sha256="$(pinned dfe6dc37d0342c891d2829b5a695aa57c2d0edecef7e7d0399a30cc6e206411e "")"
    member="cargo-mutants"
    ;;
  cargo-fuzz)
    version="0.13.2"
    url="https://github.com/rust-fuzz/cargo-fuzz/releases/download/${version}/cargo-fuzz-${version}-x86_64-unknown-linux-musl.tar.gz"
    sha256="$(pinned b5b704018b63e0f151c17a057ac53b5111e1db545d1b9f72fee79f08a545931c "")"
    member="cargo-fuzz"
    ;;
  *)
    echo "no pin for ${tool}" >&2
    exit 2
    ;;
esac

if [[ -z "$member" ]]; then
  archive="${RUNNER_TEMP:-/tmp}/${tool}"
else
  archive="${RUNNER_TEMP:-/tmp}/${tool}.${url##*.}"
fi
curl -sSfL --proto '=https' --tlsv1.2 --retry 3 --retry-delay 2 --retry-all-errors -o "$archive" "$url"
sha256sum --check --strict <<<"${sha256}  ${archive}"
mkdir -p "${HOME}/.cargo/bin"
case "$archive" in
  "${RUNNER_TEMP:-/tmp}/${tool}") install -m 0755 "$archive" "${HOME}/.cargo/bin/${tool}" ;;
  *.zip) unzip -o -j "$archive" "$member" -d "${HOME}/.cargo/bin" ;;
  *.xz) tar xJf "$archive" -C "${HOME}/.cargo/bin" --strip-components="$(tr -cd '/' <<<"$member" | wc -c)" "$member" ;;
  *) tar xzf "$archive" -C "${HOME}/.cargo/bin" --strip-components="$(tr -cd '/' <<<"$member" | wc -c)" "$member" ;;
esac
rm -f "$archive"
echo "${tool} ${version}"
