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
   1. **The key's format**, `mutation-gate proof 5`. It changes whenever what
      the key means changes, so an older proof is never read as a newer one.
      Format 2 moved the test files every key reads into the base (items 7
      and 8), in the same change as ledger format 2 (decision 3), so the one
      bump covers both. Format 3 added the static analyser to item 4. Format 4
      kept every pin and blank line of a CI definition in item 6, and moved
      `extensions`, `runner.withhold` and `ci.buildkite.step` into item 3.
      Format 5 builds each unit's key from finished digests, so another
      implementation can build it alike, and hashes each set of covering
      tests once, as the paragraph after item 9 says.
   2. **The gate**: its installed version and source reference.
   3. **The configuration** as it affects results: the effective config after
      presets, serialised canonically, with the settings that only judge or
      report left out.
      - In: `runner` with its options, `runner.withhold` (ADR-0004),
        `runner.memory` (ADR-0004, decision 9),
        `runner.workers` (ADR-0023, decision 14),
        `pest.patch`, `pest.canary`,
        `timeouts.seconds`, `timeouts.most`, `flaky.confirmSurvivors`,
        `tests.order` (ADR-0013, decision 4), `mutators.sets` and
        `mutators.except` (ADR-0021), `extensions`, the Buildkite step template
        (`ci.buildkite.step`) and the CI files the gate is told of
        (`ci.*.definition`, `ci.gitlab.template`), and what decides the trees and packages (`trees[].path`, `trees[].exclude` (ADR-0016), `treeSource`,
        `packages`).
      - Left out: floors and their reasons (`trees[].floor`,
        `trees[].reason`, `newCode`), `baseline`, `uncovered`, `ignores`,
        `timeouts.mode`, `budget`, `reports`, `badge`, the rest of `ci`, `shards`, `costs`,
        `proofs`, `reach`, `holds`, `local`, `equivalence` and `coverage`.
        `preset` is not
        in the key itself: it is expanded into the settings above.
      - Every setting is declared as affecting results, as deciding how the
        gate runs, or as judging or reporting only, and a test fails when a
        setting is none of them. Only those that affect results are in the
        key. A setting that decides how the gate runs is left out of the key
        but compared by what a change to the config file reaches (ADR-0005,
        decision 4): `preset`, `reach.everything`, `run.full`, `ci.plan`,
        `ci.defaultBranch`, `ci.check`, `ci.trustMergedPullRequests`,
        `proofs.store`, `proofs.ignore`, `proofs.write` and
        `coverage.incremental`. A key no setting declares, outside every
        setting that declares what it holds, is in the key. A new setting
        cannot be left out by accident.
   4. **The runner's identity** (ADR-0004):
      - the exact version and source reference of every package it drives,
        including, for Infection, the static analysis tool its config has kill
        mutants with (`staticAnalysisTool`). That tool's own config is a file of
        the repository like any runner config, so item 6 holds it;
      - a digest of the PHP it runs on: version, extensions and their versions,
        every ini setting except the inert ones, operating system family and
        architecture. It is the PHP the runner starts, as that PHP describes
        itself when started the way the runner starts it: the same binary,
        php.ini and options, without the variables withheld. For Infection that
        is how it starts each mutant's run, without `initialTestsPhpOptions`,
        which the config file item 6 holds. The same description tells `doctor`
        what the runner's PHP loads. The gate's own PHP is not that PHP, since a
        `-d` on the gate's command line never reaches a runner. So a memory or
        time bound is keyed at the value the runner runs with, where it can
        decide whether a mutant is killed. An inert setting only decides how an
        error is shown or logged, or how PHP's interactive shell looks
        (`display_errors`, `display_startup_errors`, `html_errors`,
        `log_errors`, `error_log`, `error_log_mode`, `docref_root`,
        `docref_ext`, `cli.pager`, `cli.prompt`). Each is named with its reason,
        and a test pins the list. Any other setting, including one PHP or an
        extension adds later, is in the digest, so a setting nobody has judged
        re-runs a unit rather than reuses its proof;
      - the static analyser that checks the mutants (ADR-0020, decision 14):
        its name, exact version and a digest of its config, or `none` where
        `staticCheck.tool` is `none` or the analyser cannot say who it is.
   5. **What is installed**: the digest of `vendor/composer/installed.json`. It
      catches a dependency installed differently from the lock. The lock itself
      is in the next item.
   6. **Every file in the repository outside the test directories**, by the git
      blob id of its content as it is on disk. That means tracked files and
      untracked files git does not ignore. It includes source, `composer.json`,
      `composer.lock`, the PHPUnit config, the files that define the runner
      (ADR-0004), templates, translations and documentation. A file a tree
      holds is source here even where the PHPUnit config keeps its tests
      beside it, unless it is a file of test cases. Five exceptions,
      none of which ever leaves out a file that defines the runner:
      - **The gate's config file** is left out, because item 3 already holds
        what of it affects results. Each `composer.json` is hashed with its
        `extra.mutation-gate` entry removed, for the same reason.
      - **CI definition files** are left out, except the ones that run the
        gate (ADR-0005, rule 1). Those are included as their text without the
        comment lines between their nodes, which no runner reads. Every
        other byte stays: each blank line, each comment after a value or
        inside one, each line of a block scalar, and the commit each action is
        pinned at, since a pin can move the gate's own action or the runtime
        it runs on. A definition whose values cannot be bounded line by line,
        or that holds a carriage return, a tab, another control character, a
        byte order mark, NEL or a Unicode line or paragraph separator, is
        included whole.
      - **The baseline file** only holds floors.
      - **The gate's own files** are left out whatever `.gitignore` says:
        `.mutation-gate/`, the directory store's `path`, the `--publish-dir`
        and every `reports` path. The ledger changes after every run, and a
        key that held it would never match again.
      - **`proofs.ignore`**, a list of globs, empty by default, is the
        project's own statement that no test reads those paths (`docs/**`,
        say). A glob that matches a file defining the runner, such as
        `phpunit.xml` or `infection.json5`, leaves that file in, and the run
        shows a warning naming the glob and the file.
   7. **Of the test directories, what every key reads**, each file by its
      digest:
      - every file under a test directory that runs code when loaded, such as
        a bootstrap;
      - every file under a test directory that defines the runner, such as
        `tests/Pest.php`, even one that only declares. A `proofs.ignore` glob
        that matches one is shown as a warning, as in item 6;
      - every test file the coverage map does not know;
      - with the Pest patch on, every test in the canary group;
      - every file under a test directory that declares a registered mutator
        the config turns on (ADR-0021);
      - the support those files name, and the support that names in turn,
        matched by the class and function names each file declares.
   8. **Of the test directories, what else can judge this unit**, each file by
      its digest:
      - the test files the runner says can judge it (ADR-0004). For a held unit,
        that is every test file, because any file can join a group. So it is,
        under Pest, for a unit whose tokens hold a class or interface constant,
        a property default, an enum case, a plain function's or closure's
        parameter default or an attribute argument, because any file can come to
        reference it (ADR-0004, decisions 5 and 8);
      - the support those files name, and the support that names in turn,
        matched as in item 7. Matching over-reads on purpose: a word that
        happens to match brings the file in.
   9. **The unit**: its path and, for each of its covered lines, the ids of the
      tests that cover it. For a unit decision 8 of ADR-0004 applies to, also
      the ids of the tests covering each reference line it follows, and of
      those covering its owners' files, for the fallback. If the tests
      covering a line change, the unit is judged again even when no file
      changed.

   Items 1 to 7 are the same for every unit of a run. They are the run's
   **base**: hashed once, and its digest recorded with every proof the run
   establishes (decision 3). A key can only match a proof of its own base.

   A unit's key is the SHA-256 of these fields, each written as its length in
   bytes, a colon, the field and a line break: the format, the base's digest,
   the digest of what item 8 reads for the unit's judging test files,
   `unit`, the unit's path, what holds it, the number of its covered lines,
   and then for each covered line, in ascending order, its number and the
   digest of the set of tests that cover it. A set's digest is the SHA-256 of
   its size and its test ids, framed alike, the ids in byte order. Each set
   is hashed once however many lines it covers: a map of the gate's own
   suite holds about 37,000 covered lines but only about 11,400 distinct
   sets, whose ids would otherwise come to about 900 MB of text.

   Where a key cannot be computed, the unit always runs and is never recorded.
   That happens with no git, or with no coverage map. Every doubt resolves the
   same way. A file in a key that did not need it costs a run. A file missing
   from a key that needed it would cost a verdict.

3. **A ledger holds one scope's proofs, timings, killer history, opening-run
   times, what it learned of its static analysers and last passing
   commit.**

   The file is compact JSON, gzipped, shown here unpacked and spread out:

   ```json
   {
       "format": 3,
       "bases": ["5be0…64 hex…", "a7c2…64 hex…"],
       "mutators": ["Plus", "LessThan"],
       "tests": ["Tests\\MoneyTest::testAdds", "Tests\\CartTest::testTotal"],
       "inputs": {
           "mutation": ["…64 hex…"],
           "tests": [["tests/MoneyTest.php", "…64 hex…"]],
           "commits": ["<commit sha>"]
       },
       "proofs": {
           "9c1e…64 hex…": {
               "unit": "src/Money.php",
               "base": "5be0…64 hex…",
               "at": "2026-09-29T20:48:17Z",
               "run": "github:<run id>/<attempt>",
               "matrix": "full",
               "mutants": [
                   { "id": "3f9a1c2b7d04", "line": 42, "status": "survived", "mutator": "LessThan", "diff": "…" },
                   ["81d0c9e2aa17", 44, 0, [0]]
               ],
               "digests": { "source": "…64 hex…", "mutation": 0, "tests": [0], "commit": 0 },
               "judging": [1]
           }
       },
       "timings": {
           "src/Money.php": { "seconds": 12.4, "runner": "infection", "at": "2026-09-29T20:48:17Z", "measuredBy": "1.2.0" }
       },
       "killers": {
           "mutants": {
               "81d0c9e2aa17": [[0, 3]]
           },
           "functions": {
               "src/Money.php": { "add": [[0, 5], [1, 1]] }
           }
       },
       "openings": {
           ".": { "infection": { "seconds": 38.2, "at": "2026-09-29T20:48:17Z" } }
       },
       "analysers": {
           "phpstan": { "checks": 63, "seconds": 31.5, "mutators": { "Plus": [60, 12] } }
       },
       "passed": { "commit": "<commit sha>", "check": "<check-run name>", "ownScopeProofs": 0,
                   "at": "2026-10-10T07:30:00Z" },
       "lastRun": { "commit": "<commit sha>", "tree": "<tree sha>", "parents": ["<commit sha>", "<commit sha>"],
                    "check": "<check-run name>", "kind": { "matrix": "full" } }
   }
   ```

   - `run` names the run that established the proof: on GitHub
     `github:<GITHUB_RUN_ID>/<GITHUB_RUN_ATTEMPT>`, on GitLab
     `gitlab:<CI_PIPELINE_ID>`, on Buildkite `buildkite:<BUILDKITE_BUILD_ID>`,
     on CircleCI `circleci:<CIRCLE_WORKFLOW_ID>`, and otherwise
     `local:<time of the run>`.
   - `matrix` is `full` where that run recorded every test that kills each
     mutant (`--kill-matrix=full`, ADR-0014 decision 7). A proof without it
     holds first killers. Where two proofs share a key, the ledger keeps the
     first, unless only the later has `matrix`.
   - Each proof also keeps the digest of each item of its content key, so
     that `doctor` can name the file whose change invalidated most proofs
     (ADR-0017). The digests are read for that alone, never to match a key.
   - `digests` records the digests of the inputs a result came from, so a
     unit a time budget never started can count by it where they are
     unchanged (ADR-0008, decision 1): `source`, the unit's file, or every
     file inside its held path; `mutation`, what decides its mutant set
     besides the source: the key's first five inputs (the format, the gate,
     the config, the runner and what is installed), the files that define the
     runner, and what of the test directories every key reads; and `tests`,
     each test
     file that killed one of its mutants, by the digest of that file with the
     support it reads outside what every key reads. `commit` is the commit
     HEAD was at when the plan took the digests, recorded only where the
     working tree then held nothing that commit does not: no change, staged
     or not, no file git neither tracks nor ignores, and no file marked
     assume-unchanged or skip-worktree, whose changes git does not show.
     Digests taken from any other working tree, or while a commit moved
     HEAD, record no commit. The plan carries the same
     digests of the run it was made for, which the verdict compares against.
   - Every digest a proof shares with others is written once, in the
     ledger's `inputs`: each mutation digest, each killing test file with its
     digest, and each commit. A proof's `mutation`, `tests` and `commit` are
     indices into those lists; only its `source` is written in full. A list
     with an entry that is not well formed is not read, and a proof that
     points into it is dropped, as one that points past its end is.
   - A mutant no test killed keeps its full record, so reports can show a
     proved survivor. A timed-out or skipped mutant also keeps its limit
     (ADR-0004), and one a static analyser killed keeps its rejection
     (ADR-0020).
   - A mutant a test killed is the tuple `[id, line, mutator, killedBy]`. `mutator`
     is its index in the ledger's `mutators`, and `killedBy` is the indices in
     the ledger's `tests` of the test that killed it first, or of every test
     that failed under a full kill matrix (ADR-0014), and is empty where no
     test is known to have killed it. Each name and each test id is listed
     once. That is what ignores, the stale-ignore check (ADR-0008) and the
     tests report (ADR-0014) need, in as few bytes as a ledger of hundreds of
     thousands of killed mutants can take.
   - A proof of a held unit records as `judging` the indices in the
     ledger's `tests` of the holding tests that run it (ADR-0005, decision
     10). A proof without `judging` names none, as a proof of a unit the
     whole suite judges, or one an earlier gate wrote, does. A proof whose
     `judging` points past the end of `tests` is dropped.
   - Each proof records the `base` of the run that established it (decision
     2), and `bases` lists the bases of the runs that wrote the ledger, the
     most recent first. A run adds its own base when it writes.
   - Retention is one fixed policy with no setting. The ledger keeps the proofs
     whose base is one of the five in `bases` seen most recently, and of those
     the newest 20,000 as a backstop, since a proof of any other base can
     never be hit again. It keeps timings only for units that still exist.
   - Where no proof in the ledger shares the run's base, planning looks up no
     proof at all, since none can match.
   - `killers` counts, for each mutant id and for each enclosing function by
     its file and its name, the tests that killed first, keeping the five
     most frequent. Each is a list of `[test, kills]` pairs, most kills
     first, `test` an index into the ledger's `tests`, which lists the tests
     the killers name as well as those killed mutants and `judging` name. Of
     two tests with as many kills, the one that killed most recently comes
     first.
   - Retention of `killers` is one fixed policy too. It keeps the mutant ids a
     kept proof holds, and of those, and of the functions, the 20,000 mutants
     and the 5,000 functions that most recently learned a killer. A run
     drops the functions of files that no longer exist before it writes.
   - Where two scopes' ledgers are read together, `killers` answers with this
     scope's ranking for a mutant or a function both know, and the default
     branch's for one only it knows.
   - `openings` keeps each package's newest opening-run time per runner.
     `killers` and `openings` only order and cut work (ADR-0013, decisions 2
     and 7), so losing them costs speed, never a verdict. The `killers`
     section is part of formats 2 and 3.
   - Reading keeps each well-formed entry and drops anything else. An
     unreadable ledger costs a run and never a verdict. A ledger of format 2
     reads as it is, its proofs recording no digests, so none of them counts
     for a unit a budget never started. A ledger of format 1, or one that is
     not a whole gzip stream, is not read, and the verdict warns where it
     is and why: a miss, never an error. A ledger not written yet reads as
     empty, with no warning. A proof
     whose digests are not well formed is dropped. Where any mutator name or test id in those lists is not text,
     every proof whose killed mutants point into that list is dropped, since
     an index past it would point at the wrong one, and where any test id is
     not text, so is all of `killers`. A `killers` pair whose index is past
     `tests`, or whose kills are not a positive count, is dropped, and so is
     a ranking left with none.
   - When two results for one key agree, the first is kept. When they differ,
     the mutants that differ are flaky and neither result is used (ADR-0008).
     Results are compared by status, a kill by static analysis reading as
     killed, since whether the analyser checked a mutant before its tests or
     after them is the run's placement, not the code (ADR-0020). `killedBy`
     is never compared, because the first killer depends on the order the
     tests ran in (ADR-0013).
   - `passed` records the newest commit of this scope whose verdict passed.
     That commit is the `last-passed` base (ADR-0005). `check` is the name of
     the check-run the verdict reported under, the one `ci.check` names
     (`mutation / verdict` by default), and `ownScopeProofs` is how
     many proofs of this scope's own ledger that verdict used.
     `ownScopeCoverage`, `true` where it is written, says the verdict's plan
     measured its coverage map against the map this scope keeps (ADR-0023,
     decision 2). `at` is the instant the verdict passed, in UTC; a record
     without it does not say, and its verdict never stands for a later commit
     (ADR-0005, decision 12). A pull request's passing run is trusted on the
     default branch only where it used neither, because its own code could
     have written both, and only as the named check-run shows it. A `passed`
     that is not such a record, one whose `ownScopeCoverage` is anything but
     `true` or whose `at` is no instant among them, reads as none.
   - `lastRun` records the commit this scope's last run judged, the
     `last-run` base (ADR-0005, decision 2), with `tree`, the tree it held,
     and `parents`, the commits it was made from in its order, so a gone
     merge commit can be made again and checked against the tree it held;
     the check-run it reported under; and `kind`, the kind of run it was: `matrix` `full` where it
     recorded every killer, `security` `true` where it made mutants with the
     security mutators alone, and `suite` where one suite's tests alone
     judged them, each written only where it holds. A run writes it only
     where it judged every unit it considered: no time budget stopped it, no
     held unit's tests missed its lines, every unit it ran left a proof, and
     git can say what the commit it judged was made of.
     Any other run that writes the ledger removes it, so a `lastRun` is
     always of the last run to write the ledger, and no result left since is
     older than it. It says where to read a change from, never whether a
     result stands: what a result records of its inputs decides that. A
     `lastRun` that is not such a record reads as none, and the rest of the
     ledger stands.

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
     default, or `s3`, `gcs` or `azure` (ADR-0028), or an extension's.

   | Backend | What it is |
   |---------|------------|
   | **Directory** (`directory`) | One file per scope, `<path>/<scope>/ledger.json.gz`, where `path` is `.mutation-gate/ledger` by default (`proofs.store: {use: directory, with: {path: …}}`). The default locally, and the base of every CI cache: GitLab's `cache:`, Buildkite's cache plugins and CircleCI's `save_cache` keep the directory. |
   | **GitHub Actions cache** | The directory store, kept by the action and the reusable workflow (ADR-0011) when their `cache` input is `true`, as it is by default. The cache service is reachable only from inside an action, so PHP never calls it. Before the run, `actions/cache/restore` restores two entries, each into its scope's directory: the newest under the prefix `mutation-gate-ledger-<SHA-256 of the ref>-`, and the newest under `mutation-gate-ledger-<SHA-256 of the default branch's ref>-`. After the verdict, `actions/cache/save` saves the run's own scope as `mutation-gate-ledger-<SHA-256 of the ref>-<SHA-256 of its ledger>`, so an unchanged ledger is not saved twice. Digests of the refs keep one scope's prefix from being a prefix of another's. |
   | **S3-compatible** (`s3`: AWS S3, Cloudflare R2, MinIO) | One object per scope, `<prefix>/<scope>/ledger.json.gz`, through `async-aws/s3`, which is in `suggest`. Its options are `bucket` (required), `prefix` (`mutation-gate` by default), `region` (`us-east-1` by default; R2 takes `auto`), `endpoint` (an `https://` URL, or `http://` where `insecureEndpoint` is true; AWS's own by default; R2's is `https://<account>.r2.cloudflarestorage.com`) and `publicUrl` (none by default: an `https://` base a run without credentials reads from, ADR-0013). Credentials come from `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and, when set, `AWS_SESSION_TOKEN`, and from nowhere else: no `~/.aws` file, instance, container or web identity role is read, so a self-hosted runner never lends the store its host's role. With `AWS_ROLE_ARN` set, those keys assume that role. |
   | **Google Cloud Storage** (`gcs`) | One object per scope, `<prefix>/<scope>/ledger.json.gz`, read and written over the XML API through `symfony/http-client`. Its options are `bucket` (required), `prefix` (`mutation-gate` by default) and `publicUrl` (none by default). Its token comes from the `external_account` file `GOOGLE_APPLICATION_CREDENTIALS` names, exchanged at Google's STS and, where the file says, for a service account's token, or from `MUTATION_GATE_GCS_TOKEN`. A service-account key is refused (ADR-0028). |
   | **Azure Blob Storage** (`azure`) | One block blob per scope, `<prefix>/<scope>/ledger.json.gz`, through `symfony/http-client`. Its options are `account` and `container` (both required), `prefix` (`mutation-gate` by default), `publicContainer`, which keeps the default branch's scope where it is set, and `publicUrl` (none by default). Its token is GitHub's OIDC token exchanged at Microsoft Entra ID for the tenant and client `AZURE_TENANT_ID` and `AZURE_CLIENT_ID` name, or `MUTATION_GATE_AZURE_TOKEN`. No account key or shared access signature is read (ADR-0028). |

   When two verdicts write one scope at the same time, the last write wins. The
   proofs it drops cost a run later, never a verdict.

5. **The scope rule rests on the store's access control.**
   - **On GitHub**, cache scoping enforces it: a cache a pull request saves is
     visible only to that pull request, and the default branch restores only
     its own.
   - **On S3**, only the credentials of trusted runs may write the default
     branch's prefix. Where those credentials come from an OIDC role, the role
     that writes the default branch's prefix trusts only a GitHub environment
     restricted to the default branch, or the `job_workflow_ref` of the
     workflow that runs the verdict, never a bare `ref`: every job a workflow
     runs on the default branch carries that `ref` (ADR-0019). A run without credentials, such as a fork's, opens the
     store read-only and reads the default branch's ledger from
     `publicUrl`, where the bucket policy makes only that prefix public
     (ADR-0013, decisions 13 to 15).
   - **On Cloud Storage and Azure Blob Storage**, the same holds for the
     identity a federation hands the store: it trusts a GitHub environment
     restricted to the default branch, or the verdict workflow's
     `job_workflow_ref`, never a bare `ref`. A run without a token reads the
     default branch's ledger from a managed folder `allUsers` may read on
     Cloud Storage, or from the public container on Azure (ADR-0028).
   - **On GitLab**, separate caches for protected branches keep a merge
     request's pipeline from writing the default branch's cache. They also
     keep it from reading that cache, so a merge request carries nothing from
     the default branch unless the ledger is in S3.
   - **On Buildkite and CircleCI**, the pipeline config a branch carries picks
     the cache key, so anyone who can push a branch can write any key. A cache
     there is no trust boundary. Keying it by branch, as the README's examples
     do, keeps branches apart by accident only. The boundary there is S3, with
     credentials that only default-branch runs hold.
   - **In the definitions `init --ci` writes**, the store's keys reach only a
     trusted run: a push, a schedule or a manual run of the default branch,
     whose name `init` writes into the definition, so an empty name trusts
     nothing. A branch can edit a definition, so its condition only keeps an
     honest run from asking for the keys. The boundary is a holder the CI
     itself restricts to the default branch, outside any file a branch can
     edit: the GitHub environment, Azure DevOps variable group, CircleCI
     context, Buildkite cluster secrets, Bitbucket deployment environment and
     Jenkins credentials `mutation-gate-store`, restricted by a deployment
     branch policy, a Branch control check, an expression restriction, an
     access policy, a deployment restriction and a folder that builds the
     default branch alone, and GitLab's protected variables. Where the plan
     and the shards run in jobs of their own, they hold no keys and read the
     default branch's ledger from `publicUrl`. A verdict that holds the keys
     still installs the project and loads its config, and GitHub's one-step
     job runs the tests beside them, so there the keys are within the
     project's reach on the default branch.
   - **`deliver` and `fetch` hold the keys in jobs that run no project code.**
     Both run from the gate's own installation, before any extension is
     found or config read, and refuse to start through Composer's proxy or
     from the working directory's `vendor`.
     - `plan --deliver-later`, `survivors --deliver-later` and
       `verdict --deliver-later` send nothing that needs a credential and
       write no store. Each leaves what it would have sent, and the verdict
       its ledger and the coverage map it keeps beside it (ADR-0023), in its
       stage's directory, `.mutation-gate/delivery/planned`,
       `.mutation-gate/delivery/survivors` or `.mutation-gate/delivery/verdict`,
       begun empty so nothing an earlier run left is sent again. The
       re-checked survivors' comment is marked to be written only over the
       comment's planned state, so `deliver` never replaces a verdict with
       it (ADR-0020 decision 21). Ledgers are
       still read from the store the config names.
     - `deliver` sends what a run that ran the project's code left in its
       delivery directory, `.mutation-gate/delivery` by default. The
       directory holds prepared payloads alone, as JSON within the ledger's
       byte limits: the ledger's scope, the comment's markdown, each alert's
       body, no more to one channel than a verdict sends, the OTLP export,
       and the scope of each object to keep beside the ledger, by the name a
       store keeps it under; the ledger and each such object, such as
       `coverage.json.gz`, lie beside them. A key it does not take refuses the
       whole delivery. `deliver` keeps each object in the store on the same
       trusted runs, and for the same scope, as it writes the ledger.
     - Every destination and every credential comes from `deliver`'s own
       environment, never from the directory. No host, endpoint or URL is
       ever read from it. The comment goes to the pull request `deliver`'s
       own event names. Each alert goes to its channel's default
       `MUTATION_GATE_*_URL`, signed with `MUTATION_GATE_WEBHOOK_SECRET`. The
       export goes to `OTEL_EXPORTER_OTLP_ENDPOINT` with its headers.
     - The store's whole location comes from `deliver`'s own environment
       alone: `MUTATION_GATE_STORE` names `s3`, `gcs` or `azure`, and
       `MUTATION_GATE_STORE_BUCKET`, `_PREFIX`, `_REGION`, `_ACCOUNT`,
       `_CONTAINER` and `_PUBLIC_CONTAINER` set its options, read as the
       config's definition reads them. `MUTATION_GATE_STORE_ENDPOINT` is
       taken for `s3` alone, and only as an `https://` URL. A run that ran
       the project's code therefore cannot name the bucket, or the Azure
       account whose request would carry the token.
     - `deliver` decides the one scope it may write from its own run before
       it reads the directory: the default branch's, on a push, a schedule
       or a manual run of that branch under GitHub Actions, the branch named
       by the event payload, else by `MUTATION_GATE_DEFAULT_BRANCH`. It
       writes the ledger only where the delivery's scope is exactly that
       one. Any other run writes no ledger.
     - `fetch` reads, with a key that only needs to read, the default
       branch's ledger alone from the store the same `MUTATION_GATE_STORE`
       variables locate, and writes it where the `directory` store reads it,
       `.mutation-gate/ledger` by default. The plan, the shards and the
       verdict then hold no credential. `fetch` writes nothing to the
       store. A ledger it cannot read costs a run, never a verdict. A public
       repository may read the default branch's ledger from `publicUrl`
       instead.

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
- [ADR-0028](0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md): the `gcs` and `azure` stores
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): `killedBy` in the proof
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): `trees[].exclude` in the key
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the per-item digests `doctor` reads
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the OIDC roles that may write the default branch's prefix
