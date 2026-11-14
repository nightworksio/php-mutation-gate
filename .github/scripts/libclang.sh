#!/usr/bin/env bash
# Usage: libclang.sh
#
# Installs the distribution's clang and libclang and names one LLVM for both
# to bindgen (CLANG_PATH, LIBCLANG_PATH), so outbreak.so's bindings are parsed
# by the libclang of the headers they include (ADR-0130). A runner holding
# several LLVMs otherwise loads the newest libclang over the default clang's
# intrinsic headers, which it cannot read.
set -euo pipefail

sudo apt-get update
sudo apt-get install --yes clang libclang-dev

resource="$(clang -print-resource-dir)"
llvm="${resource%/lib/clang/*}"

{
  echo "CLANG_PATH=${llvm}/bin/clang"
  echo "LIBCLANG_PATH=${llvm}/lib"
} >> "${GITHUB_ENV}"
