# ADR-0120: The local loop takes a share of the machine and looks only at what changed

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): how `watch` looks for a change, how often, and what a round prints as it runs
**Amends:** [0078](0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md): the priority a daemon starts at, and how it waits while idle
**Amends:** [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): how a warm worker and its client wait for each other, and how many workers a set boots

## Context

`watch` (ADR-0010) and the daemon of `serve` (ADR-0078) run on the machine a
person works at, beside their editor, browser and builds. They ran as a CI
job does:

- every look at the files listed every file with `git ls-files` and hashed
  all of them, about 0.6 CPU-s on a tree of 5,800 files, once a second, and
  a round took one look before each batch and three more after it;
- a coverage run of one test file, the one a save usually moves, started
  Pest with paratest across every core; each pool of warm workers booted
  one worker for each place however few runs it had; Pest listed the tests
  twice each round;
- every process ran at normal priority on every core, on battery as on power;
- an idle daemon woke every second, and a warm worker and its client looked
  at their files a hundred times a second;
- a round printed nothing until its verdict.

`run`, `pre-push` and CI run as many workers as `runner.*` says, as before;
a `pre-push` the daemon serves runs at the priority the daemon was started
at.

## Decision

1. **A look asks `git status` what differs.** `watch` runs
   `git --no-optional-locks status --porcelain=v2 -z --untracked-files=all`,
   which answers from the index's stat cache and takes no lock the user's own
   git commands could meet, and hashes only the paths that differ from the
   commit, tracked or not, and those that differed at the last look. Every
   other file holds what the commit holds, as it did at the last look. A look
   at another commit than the last, and the first look, list and hash every
   file. `local.watchStatus: false` hashes every file at every look.
2. **One look a wait.** While a round runs, `watch` looks for a change before
   a batch only once `local.watchPoll` has passed since its last look, and
   keeps the change it found; once the round is over, it takes at most one
   look more. `local.watchPoll` is `2s` by default.
3. **The local loop takes a share of the machine.** `local.cpu` is
   `balanced` (half the logical cores, at least one and at most eight, at
   utility priority), `fast` (every core but one, at normal priority) or
   `quiet` (one worker at background priority); `balanced` by default.
   `local.workers` (a count, `half` or `all-but-one`) and `local.priority`
   (`normal`, `utility`, `background`) set either part on its own, and each
   platform's class of the priority is a key of its own: `local.macosClass`
   (a `taskpolicy` QoS clamp: `none`, `utility`, `background`,
   `maintenance`), `local.linuxNice` (0 to 19) and `local.windowsClass`
   (`normal`, `below-normal`, `idle`).
   - The workers drive a round's coverage run, its pools and, where
     pest-plugin-mutate is patched, Pest's places: the gate passes
     `--processes`, and the patch has the plugin take it off the arguments
     once read, so neither Pest nor a mutant's own run is handed it.
   - `watch` lowers its own process, which every process a round starts
     inherits: a niceness on Linux and macOS, which a process can raise and
     never lower again; `taskpolicy`'s darwin background on macOS for the
     background and maintenance classes, set and taken back as the class
     asks; a priority class on Windows. A running process cannot be put
     under the utility QoS clamp, so `watch` running on its own takes the
     utility niceness there; the daemon `watch` starts is launched under
     `taskpolicy -c <clamp>` on macOS and `nice -n <niceness>` on Linux,
     and everything it serves inherits that.
4. **On battery or in Low Power Mode, `local.onBattery` decides:** `reduce`,
   the default, runs a round on one worker at background priority; `pause`
   holds the next round until the machine runs on power, saying so once;
   `run` changes nothing. `pre-push` and `run` are never held. The power
   source is read from `pmset` on macOS, `/sys/class/power_supply` and the
   ACPI platform profile on Linux, and PowerShell's `Win32_Battery` on
   Windows. PHP is told of no change without native code, so `watch` reads
   it at the start of each round, and at each wait while it holds one; a
   source it cannot read is taken as mains.
5. **Output-neutral speed-ups, each its own key and on by default:**
   - `local.coverageAlone`: a coverage run of one test file runs in Pest's
     own process, and a coverage run given one process runs without paratest;
   - `local.sizedPools`: a set of warm workers boots no more workers than it
     has runs, the tail's sets and the native runner's workforce alike;
   - `local.listOnce`: Pest's listing and its test names are remembered by
     the digest of the files in the test directories, which `watch` sets each
     round, so a round lists the tests only where a test file was added,
     changed or removed, and a changed one is listed again;
   - `local.streamBatches`: `watch` prints, as each batch ends, how many
     mutants it judged by status and where each survivor is, before the
     round's verdict. Output for an editor's problem matcher is unchanged.
6. **Waits block.** With no child running, the daemon waits for a request
   for the whole idle time left. A warm worker and its client pause by
   blocking on a pipe of their own that each wake signal writes a byte to,
   so a wake that comes just before a pause ends it at once. A pause no wake
   cuts short ends at its interval, which is how a worker or a client notices
   the other went away: `local.childWait` (`1s`) in the local loop, and
   `runner.poll` in a run.

## Alternatives considered

- **Native file events in PHP.** PECL `inotify` is Linux only; the other
  watchers need fswatch or Node. The Rust core's watcher replaces the look
  later; `git status` removes most of a look's cost without a new binary.
- **A stat cache in place of hashing what differs.** PHP's `stat` reports
  modification times in whole seconds, so two saves of the same size within
  a second would read as one. Hashing what git lists stays exact.
- **Re-executing `watch` under `taskpolicy -c`.** It would lose `-d` options
  given to PHP on the command line; the daemon, which the gate starts
  itself, is launched under the clamp instead.
- **Every core by default.** Fastest, and it competes with the work the
  person waits on; it is `local.cpu: fast`.

## Consequences

- On this repository's 5,825 files, a look's CPU fell from 0.59 s to 0.22 s
  (median of 7, load 16 to 18); on the quick-start fixture from 0.042 s to
  0.022 s. With the wait at 2 s, an idle `watch` on a tree that size looks
  at about a tenth of a core's worth in place of about a third.
- On a machine with 8 logical cores, a round runs on 4 workers at utility
  priority by default.
- A niceness raised on Linux or macOS, on battery under `reduce` or by
  `local.linuxNice`, stays raised until `watch` restarts.
- Pest's places follow `local.workers` only where pest-plugin-mutate is
  patched (`pest.patch`); as it ships, Pest runs as many places as cores.
- A vendor an earlier gate patched holds another gate's patch, which
  `composer reinstall pestphp/pest-plugin-mutate` and `pest:patch` replace.

## Related

- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md),
  [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md),
  [ADR-0078](0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md),
  [ADR-0112](0112-every-number-a-run-goes-by-is-a-setting.md)
