# ADR-0007: A proof is keyed by everything its result could depend on, so a skip is never wrong

**Status:** Accepted
**Date:** 2026-09-29

## Context

A unit whose result has not changed should not be mutated again. The in-house
gate skips a shard whose "proof" already passed. The proof is a SHA-256 over
the git blobs of three things:

- the shard's files;
- the tests the coverage map says run them, and every file those tests run;
- a fixed list of files every verdict reads: the lock, `phpunit.xml`,
  `tests/Pest.php`, the Pest patches, `bootstrap`, `config`, `resources` and a
  few more.

The key is stored as a GitHub Actions cache entry named after it, which holds
one small marker file.

The seed holds that key too narrow and builds it per unit. A test can depend
on code no coverage map records:

- a class constant;
- a property's default;
- an attribute read by reflection;
- a class found by scanning a directory.

None of these is an executed line, so none appears in the map. A key built from
executed files can match while the result it names has changed. The seed's
answer is that every non-test file is in every key, and only the tests are
narrowed to those that can judge the unit. It adds a ledger: a JSON file of
proofs keyed by digest, each naming the run that established it, dropping
malformed entries rather than repairing them, and keeping the newest 20,000.

The package also needs more from a proof than *passed*. Below a floor of 100 a
tree's score is added up from its units' counts (ADR-0003), so a skipped unit
has to bring its result with it.

## Decision

1. **A proof is a unit's result, stored under a content key.** The result is
   every mutant's raw record (ADR-0004), with its status before triage, ignores
   and floors are applied. A proof is written only for a unit that ran to the
   end:
   - no unjudged mutant: none the budget or a stopped runner left without a
     result (ADR-0004);
   - no flaky mutant;
   - no *cannot judge*.

   A proof with survivors is still a proof, because its survivors are its
   result. Floors, the baseline, ignores and the timeout rule of ADR-0008 apply
   at verdict time and are not in the key. A budget decides only whether a unit
   finishes, and an unfinished unit is never recorded. So raising a floor or
   adding an ignore re-runs nothing.

2. **The content key is a SHA-256 over all of the following, in this order.**
   1. **The key's format**, `mutation-gate proof 1`. It changes whenever what
      the key means changes, so an older proof is never read as a newer one.
   2. **The gate**: its installed version and source reference.
   3. **The configuration** as it affects results: the effective config after
      presets, serialised canonically, with the settings that only judge or
      report left out.
      - In: `runner` with its options, `pest.patch`, `pest.canary`,
        `timeouts.seconds`, `timeouts.retries`, `flaky.confirmSurvivors`, and
        what decides the trees and packages (`trees[].path`, `treeSource`,
        `packages`).
      - Left out: floors and their reasons (`trees[].floor`,
        `trees[].reason`, `newCode`), `baseline`, `uncovered`, `ignores`,
        `timeouts.mode`, `budget`, `reports`, `badge`, `ci`, `shards`, `costs`,
        `proofs`, `reach`, `holds`, `local` and `extensions`. `preset` is not
        in the key itself: it is expanded into the settings above.
      - Every setting is declared as affecting results or not, and a test fails
        when a setting is neither. A new setting cannot be left out by accident.
   4. **The runner's identity** (ADR-0004):
      - the exact version and source reference of every package it drives;
      - a digest of the PHP it runs on: version, extensions and their versions,
        ini settings, operating system family and architecture.
   5. **What is installed**: the digest of `vendor/composer/installed.json`. It
      catches a dependency installed differently from the lock. The lock itself
      is in the next item.
   6. **Every file in the repository outside the test directories**, by the git
      blob id of its content as it is on disk. That means tracked files and
      untracked files git does not ignore. It includes source, `composer.json`,
      `composer.lock`, the PHPUnit and runner configs, templates, translations
      and documentation. Four exceptions:
      - **The gate's config file** is left out, because item 3 already holds
        what of it affects results. Each `composer.json` is hashed with its
        `extra.mutation-gate` entry removed, for the same reason.
      - **CI definition files** are left out, except the one that runs the
        gate. That one is included as its text with comments and action pins
        (`uses: owner/repo@<sha>`) removed. A pin move or a comment is not a
        change to how a mutant runs. The seed does the same.
      - **The baseline file** only holds floors.
      - **`proofs.ignore`**, a list of globs, empty by default, is the
        project's own statement that no test reads those paths (`docs/**`,
        say).
   7. **Of the test directories, only what can judge this unit**:
      - the test files the runner says can judge it (ADR-0004). For a held unit,
        that is every test file, because any file can join a group;
      - the support those files name, and the support that names in turn,
        matched by the class and function names each file declares. Matching
        over-reads on purpose: a word that happens to match brings the file in;
      - every file under a test directory that runs code when loaded, such as
        `tests/Pest.php` and a bootstrap;
      - every test file the coverage map does not know;
      - with the Pest patch on, every test in the canary group.
   8. **The unit**: its path and, for each of its covered lines, the ids of the
      tests that cover it. If the tests covering a line change, the unit is
      judged again even when no file changed.

   Where a key cannot be computed, the unit always runs and is never recorded.
   That happens with no git, or with no coverage map. Every doubt resolves the
   same way. A file in a key that did not need it costs a run. A file missing
   from a key that needed it would cost a verdict.

3. **A ledger holds one scope's proofs, timings and last passing commit.**

   ```json
   {
       "format": 1,
       "proofs": {
           "9c1e…64 hex…": {
               "unit": "src/Money.php",
               "at": "2026-09-29T20:48:17Z",
               "run": "github:<run id>/<attempt>",
               "mutants": [
                   { "id": "3f9a1c2b7d04", "line": 42, "status": "survived", "mutator": "LessThan", "diff": "…" },
                   { "id": "81d0c9e2aa17", "line": 44, "status": "killed" }
               ]
           }
       },
       "timings": {
           "src/Money.php": { "seconds": 12.4, "runner": "infection", "at": "2026-09-29T20:48:17Z" }
       },
       "passed": "<commit sha>"
   }
   ```

   - A mutant that was not killed keeps its full record, so reports can show a
     proved survivor. A killed one keeps its id, line and status.
   - The ledger keeps the newest 20,000 proofs, and timings only for units that
     still exist.
   - Reading keeps each well-formed entry and drops anything else. An
     unreadable ledger costs a run and never a verdict.
   - When two results prove the same key, the first is kept.
   - `passed` is the newest commit of this scope whose verdict passed. That is
     the `last-passed` base (ADR-0005).

4. **The ProofStore port reads and writes one ledger per scope.** A scope is a
   ref: `refs/heads/<branch>` or `refs/pull/<n>`.
   - **Reading and writing.** A run reads its own scope and the default
     branch's, and writes only its own. The default branch's scope is written
     only by runs on that branch itself: pushes and scheduled runs. A pull
     request therefore cannot plant a proof that the default branch will
     trust.
   - **Who writes.** Only the verdict writes, after merging every shard's
     results. `proofs.write` is `auto` by default, which writes the run's own
     scope, or `never`, which makes the store read-only.
   - **Which store.** `proofs.store` chooses it (ADR-0002): `directory`, the
     default, or `s3`, or an extension's.

   | Backend | What it is |
   |---------|------------|
   | **Directory** (`directory`) | One file per scope, `<path>/<scope>/ledger.json`, where `path` is `.mutation-gate/ledger` by default (`proofs.store: {use: directory, with: {path: …}}`). The default locally, and the base of every CI cache: GitLab's `cache:`, Buildkite's cache plugins and CircleCI's `save_cache` keep the directory. |
   | **GitHub Actions cache** | The directory store, kept by the action and the reusable workflow (ADR-0011) when their `cache` input is `true`, as it is by default. The cache service is reachable only from inside an action, so PHP never calls it. Before the run, `actions/cache/restore` restores two entries, each into its scope's directory: the newest under the prefix `mutation-gate-ledger-<ref>-`, and the newest under `mutation-gate-ledger-<default branch ref>-`. After the verdict, `actions/cache/save` saves the run's own scope as `mutation-gate-ledger-<ref>-<SHA-256 of its ledger>`, so an unchanged ledger is not saved twice. |
   | **S3-compatible** (`s3`: AWS S3, Cloudflare R2, MinIO) | One object per scope, `<prefix>/<scope>/ledger.json`, through `async-aws/s3`, which is in `suggest`. Its options are `bucket` (required), `prefix` (`mutation-gate` by default), `region` (`us-east-1` by default; R2 takes `auto`) and `endpoint` (AWS's own by default; R2's is `https://<account>.r2.cloudflarestorage.com`). Credentials come from `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and, when set, `AWS_SESSION_TOKEN`. |

   When two verdicts write one scope at the same time, the last write wins. The
   proofs it drops cost a run later, never a verdict.

5. **The scope rule rests on the store's access control.**
   - **On GitHub**, cache scoping enforces it: a cache a pull request saves is
     visible only to that pull request, and the default branch restores only
     its own.
   - **On S3**, only the credentials of trusted runs may write the default
     branch's prefix.
   - **On other CIs**, the directory is kept by the CI's own cache, so that
     cache must be keyed by ref, as the README's examples are: a pull request
     that could save the cache the default branch restores could plant a
     proof in it. GitLab's separate caches for protected branches give the same
     boundary.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Key over the files the judging tests executed** (the in-house gate's key) | Misses what no coverage map records: constants, defaults, attributes read by reflection, classes found by scanning. The key can then match while the result it names has changed. |
| **One proof per shard** (the in-house gate's) | A shard's contents move with every timing (ADR-0006), and one changed file in it invalidates the whole shard. |
| **Only *passed* in a proof** | Enough at a floor of 100. Below it, a tree's score needs every unit's counts. |
| **Floors or ignores in the key** | Raising a floor or adding an ignore would re-run every unit, though no mutant's result depends on either. |
| **Content from the git index rather than from disk** | A local run with uncommitted changes could then neither use nor record proofs. Hashing what is on disk works the same everywhere. |
| **Narrowing a monorepo package's key to its own files and its path dependencies** | Higher hit rate. It can be wrong for a test that reads a sibling's files by relative path, and a proof that can be wrong is not a proof. `proofs.ignore` is the explicit, reviewable way to narrow it. |
| **One shared ledger that pull requests write too** | A pull request could plant a proof for a tree the default branch will later compute, and the default branch would skip on it. Scoping by ref makes trust follow branch protection. |
| **`aws/aws-sdk-php` for S3** | Far larger than one bucket needs. `async-aws/s3` is a focused S3 client, and it stays optional. |
| **Calling the Actions cache service from PHP** | The service is internal to the Actions runtime. `actions/cache` is its supported interface. |

## Consequences

**The key is strict, and its hit rate is the price.** A source change anywhere
in the repository invalidates every proof, because any file might be read by
any test. Proofs pay for themselves on:

- retried and re-run jobs;
- re-runs of unchanged code;
- test-only changes elsewhere;
- the scheduled full run, which re-mutates only what moved since the last one.

Reach (ADR-0005), not proofs, is what keeps a pull request small.

**A skipped unit brings its result.** Reports show a proved survivor exactly as
they show a fresh one, marked with the run that established it.

**The trust boundary is the store's access control.** Ledger entries are not
signed, and the README says where the boundary lies for each store.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): why a proof carries counts
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): runner identity and judging tests
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): `last-passed` and the scheduled full run
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): timings, and the verdict that writes the ledger
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): why budget-cut and flaky units are never recorded
