# How CI works

Every check a pull request runs is listed in
[`.github/gates.json`](../../.github/gates.json), with what it checks, the
command that reproduces it here, and the command that fixes it where a fix is
mechanical. [Contributing](../../CONTRIBUTING.md#the-local-gates) says which
to run before a push.

## The workflows

| Workflow | Runs | On |
|----------|------|----|
| [`ci.yml`](../../.github/workflows/ci.yml) | Every gate on PHP and the scripts, hygiene, the site, Outbreak's core, Tally's counts and the package's own mutation gate | every pull request and push to `main`, and nightly |
| [`pr.yml`](../../.github/workflows/pr.yml) | The pull request's commits, title and body, in one job `pr` | every pull request, and each edit of its title or body |
| [`hygiene.yml`](../../.github/workflows/hygiene.yml) | actionlint, zizmor, typos, lychee, markdownlint, the line cap, gitleaks and osv-scanner, in one job `tools` | called by `ci.yml` |
| [`site.yml`](../../.github/workflows/site.yml) | The docs site's build and its link checks | called by `ci.yml` and `pages.yml` |
| [`pages.yml`](../../.github/workflows/pages.yml) | The versioned docs site, deployed to GitHub Pages | a push to `main` that changes the docs, and on demand |
| [`mutation-gate.yml`](../../.github/workflows/mutation-gate.yml) | The reusable workflow projects call, which `ci.yml` calls for the package's own gate | called |
| [`end-to-end.yml`](../../.github/workflows/end-to-end.yml) | The gate installed into another project: warm workers, and the hook managers | called by `ci.yml` |
| [`outbreak.yml`](../../.github/workflows/outbreak.yml) | Outbreak's core: the Rust gates, the boundary contract suites, and the binaries and `outbreak.so` for each platform | called by `ci.yml` |
| [`tally.yml`](../../.github/workflows/tally.yml) | The hot paths' counts under Tally ([ADR-0154](../decisions/0154-the-gate-s-hot-paths-are-counted-by-tally.md)) | called by `ci.yml` |
| [`local-mode.yml`](../../.github/workflows/local-mode.yml) | The local mode and the local loop on a pinned project | every pull request and push to `main` |
| [`claim-race.yml`](../../.github/workflows/claim-race.yml) | The race of shard claims ([ADR-0134](../decisions/0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md)) | nightly, and on demand |
| [`canary.yml`](../../.github/workflows/canary.yml) | The runner and static checker contracts against the newest release of each tool | daily |
| [`codeql.yml`](../../.github/workflows/codeql.yml), [`scorecard.yml`](../../.github/workflows/scorecard.yml) | CodeQL over the workflows, and OpenSSF Scorecard | pushes to `main`, pull requests for CodeQL, and a schedule |
| [`bench.yml`](../../.github/workflows/bench.yml) | The benchmark | on demand and monthly |
| [`release.yml`](../../.github/workflows/release.yml) | Drafting and publishing a release | on demand, and a pushed release tag |
| [`bot-commands.yml`](../../.github/workflows/bot-commands.yml) | `/update`, `/rebase`, `/retest` and `/docs` | a comment |

## What a pull request runs

`scope`, shown as *what changed*, reads the paths a pull request changed and
says which PHP gates it needs
([`.github/scripts/scope.py`](../../.github/scripts/scope.py)): a change to
documentation alone runs none of them, and a runner's contract legs run only
where its adapter, its fixture or what every runner shares changed. Every job
still reports, skipped where `scope` leaves it out, so each one can be a
required check. A push to `main` and the nightly run take every job.

## The package's own gate

The `mutation` job runs the package's own reusable workflow over itself, with
the coverage map the `coverage` job joins from the tests' and the runner
contract's reports
([ADR-0152](../decisions/0152-the-gate-s-own-map-joins-the-runner-contract-s-report.md)), holding each tree to the floor
`mutation-gate.baseline.json` records for it
([ADR-0011](../decisions/0011-the-package-holds-itself-to-the-gate-it-ships.md)).
Its config is [`mutation-gate.json`](../../mutation-gate.json), and its proofs
live in the GitHub Actions cache. The default branch publishes the badge and
the trend to the `mutation-gate` branch.

## Evidence and the bot

Every job leaves its evidence, the findings of each tool in a structured form,
as an artifact the bot reads. The bot explains each failure from tables on
`main`, fixes what is mechanical, and runs no pull request code where it can
write
([ADR-0019](../decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md)).
