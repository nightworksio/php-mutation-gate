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
   - no unjudged mutant (ADR-0004), except one that ADR-0004's decision 8 left
     unjudged because of what the code holds: no test references the value,
     or the reference is ambiguous beyond the bound. The same key gives the
     same answer, so such a unit is still proved;
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
        `timeouts.seconds`, `timeouts.retries`, `flaky.confirmSurvivors`,
        `tests.order` (ADR-0013, decision 4), and what decides the trees and packages (`trees[].path`, `treeSource`,
        `packages`).
      - Left out: floors and their reasons (`trees[].floor`,
        `trees[].reason`, `newCode`), `baseline`, `uncovered`, `ignores`,
        `timeouts.mode`, `budget`, `reports`, `badge`, `ci`, `shards`, `costs`,
        `proofs`, `reach`, `holds`, `local`, `equivalence` and `extensions`.
        `preset` is not
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
      and documentation. Five exceptions:
      - **The gate's config file** is left out, because item 3 already holds
        what of it affects results. Each `composer.json` is hashed with its
        `extra.mutation-gate` entry removed, for the same reason.
      - **CI definition files** are left out, except the ones that run the
        gate (ADR-0005, rule 1). Those are included as their text with comments and action pins
        (`uses: owner/repo@<sha>`) removed. A pin move or a comment is not a
        change to how a mutant runs. The seed does the same.
      - **The baseline file** only holds floors.
      - **The gate's own files** are left out whatever `.gitignore` says:
        `.mutation-gate/`, the directory store's `path`, the `--publish-dir`
        and every `reports` path. The ledger changes after every run, and a
        key that held it would never match again.
      - **`proofs.ignore`**, a list of globs, empty by default, is the
        project's own statement that no test reads those paths (`docs/**`,
        say).
   7. **Of the test directories, only what can judge this unit**:
      - the test files the runner says can judge it (ADR-0004). For a held unit,
        that is every test file, because any file can join a group. So it is,
        under Pest, for a unit whose tokens hold a class or interface constant,
        a property default, an enum case, a plain function's or closure's
        parameter default or an attribute argument, because any file can come to
        reference it (ADR-0004, decisions 5 and 8);
      - the support those files name, and the support that names in turn,
        matched by the class and function names each file declares. Matching
        over-reads on purpose: a word that happens to match brings the file in;
      - every file under a test directory that runs code when loaded, such as
        `tests/Pest.php` and a bootstrap;
      - every test file the coverage map does not know;
      - with the Pest patch on, every test in the canary group.
   8. **The unit**: its path and, for each of its covered lines, the ids of the
      tests that cover it. For a unit decision 8 of ADR-0004 applies to, also
      the ids of the tests covering each reference line it follows, and of
      those covering its owners' files, for the fallback. If the tests
      covering a line change, the unit is judged again even when no file
      changed.

   Where a key cannot be computed, the unit always runs and is never recorded.
   That happens with no git, or with no coverage map. Every doubt resolves the
   same way. A file in a key that did not need it costs a run. A file missing
   from a key that needed it would cost a verdict.

3. **A ledger holds one scope's proofs, timings, killer history, opening-run
   times and last passing commit.**

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
                   { "id": "81d0c9e2aa17", "line": 44, "status": "killed", "mutator": "Plus" }
               ]
           }
       },
       "timings": {
           "src/Money.php": { "seconds": 12.4, "runner": "infection", "at": "2026-09-29T20:48:17Z" }
       },
       "killers": {
           "mutants": {
               "81d0c9e2aa17": { "Tests\\MoneyTest::testAdds": 3 }
           },
           "functions": {
               "src/Money.php Money::add": { "Tests\\MoneyTest::testAdds": 5, "Tests\\CartTest::testTotal": 1 }
           }
       },
       "openings": {
           ".": { "infection": { "seconds": 38.2, "at": "2026-09-29T20:48:17Z" } }
       },
       "passed": "<commit sha>"
   }
   ```

   - `run` names the run that established the proof: on GitHub
     `github:<GITHUB_RUN_ID>/<GITHUB_RUN_ATTEMPT>`, on GitLab
     `gitlab:<CI_PIPELINE_ID>`, on Buildkite `buildkite:<BUILDKITE_BUILD_ID>`,
     on CircleCI `circleci:<CIRCLE_WORKFLOW_ID>`, and otherwise
     `local:<time of the run>`.
   - A mutant that was not killed keeps its full record, so reports can show a
     proved survivor. A killed one keeps its id, line, mutator and status,
     which ignores and the stale-ignore check need (ADR-0008), and `killedBy`,
     the test that killed it first, or every test that failed under a full
     kill matrix (ADR-0014). A timed-out or
     skipped mutant also keeps its limit (ADR-0004).
   - The ledger keeps the newest 20,000 proofs, and timings only for units that
     still exist.
   - `killers` counts, for each mutant id and for each enclosing function, the
     tests that killed first, keeping the five most frequent. Entries for
     mutant ids no kept proof holds, and for functions whose unit is gone, are
     dropped. `openings` keeps each package's newest opening-run time per
     runner. Both only order and cut work (ADR-0013, decisions 2 and 7), so
     losing them costs speed, never a verdict.
   - Reading keeps each well-formed entry and drops anything else. An
     unreadable ledger costs a run and never a verdict.
   - When two results for one key agree, the first is kept. When they differ,
     the mutants that differ are flaky and neither result is used (ADR-0008).
     Results are compared by status. `killedBy` is never compared, because
     the first killer depends on the order the tests ran in (ADR-0013).
   - `passed` is the newest commit of this scope whose verdict passed. That is
     the `last-passed` base (ADR-0005).

4. **The ProofStore port reads and writes one ledger per scope.** A scope is a
   ref: `refs/heads/<branch>` or `refs/pull/<n>`.
   - **Reading and writing.** A run reads its own scope and the default
     branch's, and writes only its own. A run with no ref has no scope and
     writes nothing (ADR-0006). The default branch's scope is written only by
     runs on that branch itself: pushes and scheduled runs. A pull request
     therefore cannot plant a proof that the default branch will trust.
   - **Who writes.** Only the verdict writes, after merging every shard's
     results. `proofs.write` is `auto` by default, which writes the run's own
     scope, or `never`, which makes the store read-only.
   - **Which store.** `proofs.store` chooses it (ADR-0002): `directory`, the
     default, or `s3`, or an extension's.

   | Backend | What it is |
   |---------|------------|
   | **Directory** (`directory`) | One file per scope, `<path>/<scope>/ledger.json`, where `path` is `.mutation-gate/ledger` by default (`proofs.store: {use: directory, with: {path: …}}`). The default locally, and the base of every CI cache: GitLab's `cache:`, Buildkite's cache plugins and CircleCI's `save_cache` keep the directory. |
   | **GitHub Actions cache** | The directory store, kept by the action and the reusable workflow (ADR-0011) when their `cache` input is `true`, as it is by default. The cache service is reachable only from inside an action, so PHP never calls it. Before the run, `actions/cache/restore` restores two entries, each into its scope's directory: the newest under the prefix `mutation-gate-ledger-<SHA-256 of the ref>-`, and the newest under `mutation-gate-ledger-<SHA-256 of the default branch's ref>-`. After the verdict, `actions/cache/save` saves the run's own scope as `mutation-gate-ledger-<SHA-256 of the ref>-<SHA-256 of its ledger>`, so an unchanged ledger is not saved twice. Digests of the refs keep one scope's prefix from being a prefix of another's. |
   | **S3-compatible** (`s3`: AWS S3, Cloudflare R2, MinIO) | One object per scope, `<prefix>/<scope>/ledger.json`, through `async-aws/s3`, which is in `suggest`. Its options are `bucket` (required), `prefix` (`mutation-gate` by default), `region` (`us-east-1` by default; R2 takes `auto`), `endpoint` (AWS's own by default; R2's is `https://<account>.r2.cloudflarestorage.com`) and `publicUrl` (none by default: an `https://` base a run without credentials reads from, ADR-0013). Credentials come from `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and, when set, `AWS_SESSION_TOKEN`. |

   When two verdicts write one scope at the same time, the last write wins. The
   proofs it drops cost a run later, never a verdict.

5. **The scope rule rests on the store's access control.**
   - **On GitHub**, cache scoping enforces it: a cache a pull request saves is
     visible only to that pull request, and the default branch restores only
     its own.
   - **On S3**, only the credentials of trusted runs may write the default
     branch's prefix. A run without credentials, such as a fork's, opens the
     store read-only and reads the default branch's ledger from
     `publicUrl`, where the bucket policy makes only that prefix public
     (ADR-0013, decisions 13 to 15).
   - **On GitLab**, separate caches for protected branches keep a merge
     request's pipeline from writing the default branch's cache. They also
     keep it from reading that cache, so a merge request carries nothing from
     the default branch unless the ledger is in S3.
   - **On Buildkite and CircleCI**, the pipeline config a branch carries picks
     the cache key, so anyone who can push a branch can write any key. A cache
     there is no trust boundary. Keying it by branch, as the README's examples
     do, keeps branches apart by accident only. The boundary there is S3, with
     credentials that only default-branch runs hold.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Key over the files the covering tests executed** (the in-house gate's key) | Misses what no coverage map records: constants, defaults, attributes read by reflection, classes found by scanning. The key can then match while the result it names has changed. |
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
- test-only changes elsewhere, except for held units and for units whose
  values ADR-0004's decision 8 judges, whose keys hold every test file;
- the scheduled full run, which re-mutates only what moved since the last one.

Reach (ADR-0005), not proofs, is what keeps a pull request small.

**A skipped unit brings its result.** Reports show a proved survivor exactly as
they show a fresh one, marked with the run that established it.

**The trust boundary is the store's access control.** Ledger entries are not
signed, and the README says where the boundary lies for each store.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): why a proof carries counts
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): runner identity, and the tests that can judge a unit
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): `last-passed` and the scheduled full run
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): timings, and the verdict that writes the ledger
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): why budget-cut and flaky units are never recorded
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): `tests.order` in the key, the killer history and opening runs in the ledger, and forks reading the S3 store
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): `killedBy` in the proof
