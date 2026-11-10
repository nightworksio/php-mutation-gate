# ADR-0145: A stream may fork each test from an application booted once

**Status:** Accepted
**Date:** 2026-11-10
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): what a stream's parent runs each test in, where `splitStream.bootOnce` is on

## Context

A split stream's parent runs every test of its part in one process
(ADR-0117). A Laravel test boots the application in its `setUp` and tears
it down after: on companion, 300 tests that only assert an integer cost
12.7–12.9 s of CPU against 0.5–0.6 s for plain Pest tests, about 41 ms of
CPU per test. That boot is 36–53% of companion's suite, so of every
stream parent's pass. A child forked at a site already skips the boot; the
parent pays it once per test.

Laravel 11 and later boot through the framework's own
`TestCase::createApplication()`, which requires the `bootstrap/app.php`
under `Application::inferBasePath()`, and that reads `APP_BASE_PATH`. A
test case whose `app` is already set skips the boot.

## Decision

1. **`splitStream.bootOnce`, off by default, keyed.** Where it is on, a
   first-pass stream that records no lines (ADR-0135) points
   `APP_BASE_PATH` at a directory of its own whose `bootstrap/app.php` asks
   the stream for the application.
2. **The stream boots the application once.** The first test that asks
   boots the project's own `bootstrap/app.php` and its console kernel in
   the stream's process. Every site that boot runs leaves its mutant out of
   this stream (a `booted` line) and to the second pass, which streams with
   a boot in every test, as a run without the setting does.
3. **Each test runs in a process of its own, forked from it.** Where the
   test holds no descriptor a fork would share (ADR-0117 decision 7), the
   stream forks: the test's own process takes the booted application, runs
   the test as a stream's parent does, forking its mutants' children at
   their sites and judging them against itself, and ends with the test
   once its children ended. The stream waits for it, reads the kills it
   wrote into its own, and skips the test; where the test's process never
   ended the test, the stream writes a fallback for it, and its mutants run
   their own runs.
4. **Anything else boots as before.** A test the stream cannot fork in, an
   application that does not boot, a project without `bootstrap/app.php`,
   and a test case that requires `bootstrap/app.php` itself (Laravel 10's
   skeleton) take a fresh application as they do without the gate.
5. **It is a loose value where it is on** (ADR-0142): a test no longer sees
   the state the tests before it left in the process, so a mutant can be
   killed or survive where the whole-suite stream judges it otherwise, the
   class of ADR-0136. It moves to on only once an A/B on companion, this
   against the stream that boots in every test, lists no mutant whose
   status differs.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `splitStream.bootOnce` | **`true`**, `false` | `false` | affects results |

## Consequences

- A Laravel suite's stream parents lose the boot of every test but one: 31%
  to 47% of the parent pass on companion's model, a fork of 3–7.5 ms in its
  place.
- A boot that differs from test to test (random keys, temporary paths made
  at boot) is the booted one in every test's process.
- Laravel's parallel-testing hooks still run: the booted application is
  handed in inside Laravel's own `if (! $this->app)` block.
