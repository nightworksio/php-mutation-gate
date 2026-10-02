// The vendored braces (vendor/braces) that micromatch resolves in place of
// braces 3.0.3: it refuses a pattern nested past its depth cap with a
// SyntaxError, and expands every other pattern as braces 3.0.3 does. Run by
// the `hygiene / markdown` job after `npm ci`.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { realpathSync } from 'node:fs';
import { sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const braces = require('braces');
const { MAX_DEPTH } = require('braces/lib/constants');
const vendored = fileURLToPath(new URL('./vendor/braces/', import.meta.url));
const nested = depth => `${'{'.repeat(depth)}a,b${'}'.repeat(depth)}`;
const walkers = { compile: braces.compile, expand: braces.expand };

test('the braces micromatch requires is the vendored copy', () => {
  const micromatch = createRequire(require.resolve('micromatch'));

  assert.ok(realpathSync(micromatch.resolve('braces')).startsWith(realpathSync(vendored) + sep));
});

test('a pattern nested past the cap is refused with a SyntaxError, not a stack overflow', () => {
  for (const [name, walk] of Object.entries(walkers)) {
    assert.throws(() => walk(nested(4000)), SyntaxError, name);
    assert.throws(() => walk(nested(MAX_DEPTH + 1)), SyntaxError, name);
  }
});

test('a nested paren counts toward the cap as a brace does', () => {
  const parens = `${'('.repeat(MAX_DEPTH + 1)}a${')'.repeat(MAX_DEPTH + 1)}`;

  assert.throws(() => braces.compile(parens), SyntaxError);
});

test('a pattern nested to the cap expands', () => {
  for (const [name, walk] of Object.entries(walkers)) {
    assert.doesNotThrow(() => walk(nested(MAX_DEPTH)), name);
  }
});

test('maxDepth lowers the cap and never raises it', () => {
  assert.throws(() => braces.expand(nested(3), { maxDepth: 2 }), SyntaxError);
  assert.throws(() => braces.expand(nested(MAX_DEPTH + 1), { maxDepth: MAX_DEPTH * 2 }), SyntaxError);
});

test('it expands and compiles as braces 3.0.3 does', () => {
  assert.deepEqual(braces.expand('a/{b,c}/{1..3}'), ['a/b/1', 'a/b/2', 'a/b/3', 'a/c/1', 'a/c/2', 'a/c/3']);
  assert.deepEqual(braces('a/{b,{c,d}}'), ['a/(b|(c|d))']);
  assert.deepEqual(braces.expand('{a,{b,{c,d}}}'), ['a', 'b', 'c', 'd']);
});
