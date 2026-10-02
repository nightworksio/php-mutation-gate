# @nightworksio/braces

braces 3.0.3 by Jon Schlinkert, MIT (see `LICENSE`), with a
nesting-depth cap. micromatch, which markdownlint-cli2 uses to match globs,
requires `braces`; `package.json` one directory up resolves that to this copy
through a `file:` dependency and an `overrides` entry.

## What differs from braces 3.0.3

- `lib/constants.js` declares `MAX_DEPTH: 256`.
- `lib/parse.js` refuses a brace or paren block nested deeper than
  `MAX_DEPTH` with a `SyntaxError`. The `maxDepth` option lowers the cap and
  never raises it; a value that is not a finite number leaves it at
  `MAX_DEPTH`.
- `package.json` names the package `@nightworksio/braces` and carries no
  development dependencies or scripts.

## Why

braces 3.0.3 compiles and expands its syntax tree with recursive walkers that
have no depth guard, so a pattern nested a few thousand levels deep under the
10,000-character limit exhausts the call stack with a `RangeError`
(GHSA-vfj7-8cjw-p6xm). No braces release fixes it. The cap refuses such a
pattern, given as text, while parsing, before any walker recurses; micromatch
gives braces only text. The package's own name keeps
osv-scanner from matching this copy to the advisory, which names `braces`.

`../braces.test.mjs` checks the cap, that micromatch resolves this copy, and
that every other pattern expands as braces 3.0.3 expands it.
