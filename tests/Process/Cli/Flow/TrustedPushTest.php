<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveryDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\FetchedCopies;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Copies;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

const TRUSTED_PUSH_API = 'https://api.github.com/repos/octo/gate';

const TRUSTED_PUSH_CHECK = 'mutation / verdict';

afterEach(function (): void {
    Scratch::sweep();
});

/** The project in a real repository: src/Money.php uses the trait src/Equals.php declares, and src/Held.php neither. */
function trustedPushProject(): Repository
{
    return Repository::empty()
        ->write('.gitignore', ".mutation-gate/\n")
        ->write('composer.json', "{}\n")
        ->write('src/Money.php', "<?php\n\nfinal class Money\n{\n    use Equals;\n}\n")
        ->write('src/Equals.php', "<?php\n\ntrait Equals\n{\n}\n")
        ->write('src/Held.php', "<?php\n\nfinal class Held\n{\n}\n")
        ->write('tests/MoneyTest.php', "<?php\n\nit('adds', fn () => expect(1)->toBe(1))->group('mutation-canary');\n")
        ->commit('The code.');
}

function trustedPushHead(Repository $repository): string
{
    return trim($repository->git('rev-parse', 'HEAD'));
}

function trustedPushSettings(): Settings
{
    return JudgingRuns::settings(Ci::check(TRUSTED_PUSH_CHECK), Ci::trustingMergedPullRequests(), Equivalence::notProvenStatically());
}

/**
 * GitHub's word on a push of the head: the one commit since the base is the
 * squash of pull request 7, whose branch's head has the same tree and whose
 * verdict passed there.
 */
function trustedPushGitHub(string $base, string $head, string $pullHead, string $pullTree, string $pushedTree): MockHttpClient
{
    $answers = [
        sprintf('%s/compare/%s...%s', TRUSTED_PUSH_API, $base, $head) => [
            'total_commits' => 1,
            'commits' => [['sha' => $head, 'commit' => ['tree' => ['sha' => $pushedTree]]]],
        ],
        sprintf('%s/commits/%s/pulls', TRUSTED_PUSH_API, $head) => [[
            'number' => 7,
            'merged_at' => '2026-11-07T10:00:00Z',
            'head' => ['sha' => $pullHead],
            'base' => ['ref' => 'main', 'repo' => ['full_name' => 'octo/gate', 'default_branch' => 'main']],
        ]],
        sprintf('%s/git/commits/%s', TRUSTED_PUSH_API, $pullHead) => ['tree' => ['sha' => $pullTree]],
        sprintf('%s/commits/%s/check-runs?check_name=mutation%%20%%2F%%20verdict&status=completed', TRUSTED_PUSH_API, $pullHead) => [
            'check_runs' => [['id' => 1, 'name' => TRUSTED_PUSH_CHECK, 'conclusion' => 'success', 'app' => ['slug' => 'github-actions']]],
        ],
    ];

    return new MockHttpClient(static fn(string $method, string $url): ResponseInterface => array_key_exists($url, $answers)
        ? new JsonMockResponse($answers[$url])
        : new MockResponse('{"message": "Not Found"}', ['http_code' => 404]));
}

/**
 * The ports of a push at the repository's head, reading GitHub only where a client is given.
 *
 * @return list<object>
 */
function trustedPushPorts(Repository $repository, ProofStore $store, MockHttpClient|NotGiven $github): array
{
    $git = Git::at($repository->root);
    $over = $github instanceof MockHttpClient ? PassedPullRequests::over(
        $git,
        $github,
        ['GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_SHA' => trustedPushHead($repository), 'GITHUB_TOKEN' => 'secret'],
        TRUSTED_PUSH_CHECK,
    ) : $git;

    return [$over instanceof PassedPullRequests ? $over->trusting($store) : $over, $store, JudgingRuns::tree(Floor::of(0))];
}

/** @param list<object> $ports */
function trustedPushPlan(Repository $repository, Mode $mode, array $ports): Plan
{
    $plan = Planned::from(new Planning(
        Flows::adapters($repository->root, [], ...$ports),
        trustedPushSettings(),
        Flows::setup(),
    )->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

    return $plan instanceof Plan ? $plan : throw new RuntimeException($plan->why());
}

/** @param list<object> $ports */
function trustedPushJudged(Repository $repository, Plan $plan, array $ports): Judged
{
    $judged = JudgingRuns::judged(
        $plan,
        Flows::adapters($repository->root, [], ...$ports),
        trustedPushSettings(),
        JudgingRuns::reporting(new ReporterFake()),
    );

    return $judged instanceof Judged
        ? $judged
        : throw new RuntimeException($judged instanceof CannotJudge ? $judged->why() : 'The config is invalid.');
}

/**
 * The paths of the units a plan runs.
 *
 * @return list<string>
 */
function trustedPushRan(Plan $plan): array
{
    $paths = [];

    foreach ($plan as $shard) {
        foreach ($shard->units() as $unit) {
            $paths[] = $unit->path()->value();
        }
    }

    sort($paths);

    return $paths;
}

/**
 * The paths of the units a plan carries.
 *
 * @return list<string>
 */
function trustedPushCarried(Plan $plan): array
{
    $paths = array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$plan->considered()->carried()]);
    sort($paths);

    return $paths;
}

/**
 * The project with main's ledger having judged its first commit whole, and a
 * squash of pull request 7 made by this change on main, the pull request's
 * own ledger recording its head as passed with this many of its own proofs.
 *
 * @return array{Repository, ProofStore, MockHttpClient, string, string}
 */
function trustedPushMerged(Closure $change, int $ownScopeProofs = 0): array
{
    $repository = trustedPushProject();

    return trustedPushMergedIn($repository, new ProofStoreFake(), $change, $ownScopeProofs);
}

/**
 * That, in this repository and this store.
 *
 * @return array{Repository, ProofStore, MockHttpClient, string, string}
 */
function trustedPushMergedIn(Repository $repository, ProofStore $store, Closure $change, int $ownScopeProofs = 0): array
{
    $base = trustedPushHead($repository);
    $ports = trustedPushPorts($repository, $store, NotGiven::value());
    $full = trustedPushPlan($repository, Mode::full(), $ports);
    $first = trustedPushJudged($repository, $full, $ports);
    expect($first->verdict->judgement())->toBe(Judgement::Passed);
    $passed = $store->read(Scope::branch('main'));
    expect($passed instanceof Ledger ? $passed->runs()->passed() : $passed)->toBeInstanceOf(Passed::class);

    $change($repository);
    $repository->commit('The pull request, squashed.');
    $head = trustedPushHead($repository);
    $tree = trim($repository->git('rev-parse', 'HEAD^{tree}'));
    $pullHead = str_repeat('e7', 20);
    trustedPushRecorded($repository, $store, Passed::of(Revision::ref($pullHead), TRUSTED_PUSH_CHECK, $ownScopeProofs));

    return [$repository, $store, trustedPushGitHub($base, $head, $pullHead, $tree, $tree), $base, $head];
}

/**
 * The pull request's pass, kept as the run keeps it: in its own scope's ledger
 * in memory, and through the file its delivery leaves into what fetch hands a
 * store on disk.
 */
function trustedPushRecorded(Repository $repository, ProofStore $store, Passed $passed): void
{
    $ledger = Ledger::empty()->atBase(Digest::sha256Of('the pull request\'s base'))->withRuns(ScopeRuns::none()->passing($passed));

    if ($store instanceof ProofStoreFake) {
        $store->write(Scope::pullRequest(7), $ledger);

        return;
    }

    DeliveryDirectory::of(Directory::at($repository->root), Stage::Verdict)->ledger(Scope::pullRequest(7), $ledger);
    Scratch::write(
        $repository->root,
        sprintf('.mutation-gate/fetched/refs/pull/7/%s', LedgerFile::NAME),
        (string) file_get_contents(sprintf('%s/.mutation-gate/delivery/verdict/%s', $repository->root, LedgerFile::PASSED_FILE)),
    );
}

$equals = static fn(Repository $repository): Repository => $repository
    ->write('src/Equals.php', "<?php\n\ntrait Equals\n{\n    public int \$cents = 0;\n}\n");
$test = static fn(Repository $repository): Repository => $repository
    ->write('tests/MoneyTest.php', "<?php\n\nit('adds', fn () => expect(2)->toBe(2))->group('mutation-canary');\n");
$manifest = static fn(Repository $repository): Repository => $repository
    ->write('composer.json', "{\"name\": \"octo/gate\"}\n");
$pushes = [
    'a source file' => [$equals, ['src/Equals.php'], ['src/Equals.php'], ['src/Held.php', 'src/Money.php']],
    'a test' => [$test, ['src/Money.php'], [], ['src/Equals.php', 'src/Held.php', 'src/Money.php']],
    'a file that decides every unit' => [$manifest, ['src/Equals.php', 'src/Held.php', 'src/Money.php'], [], ['src/Equals.php', 'src/Held.php', 'src/Money.php']],
];

it('runs on a push what the change reaches, and on a trusted push only the units whose source changed, carrying the rest', function (Closure $change, array $plain, array $trusted, array $carried): void {
    [$repository, $store, $github] = trustedPushMerged($change);
    $mode = Mode::since(Mode::LAST_PASSED);

    $unasked = trustedPushPlan($repository, $mode, trustedPushPorts($repository, $store, NotGiven::value()));
    $asked = trustedPushPlan($repository, $mode, trustedPushPorts($repository, $store, $github));

    expect(trustedPushRan($unasked))->toBe($plain)
        ->and(trustedPushRan($asked))->toBe($trusted)
        ->and(trustedPushCarried($asked))->toBe($carried);
})->with($pushes);

it('takes no pull request\'s pass that used its own proofs', function () use ($test): void {
    [$repository, $store, $github] = trustedPushMerged($test, ownScopeProofs: 1);

    $plan = trustedPushPlan($repository, Mode::since(Mode::LAST_PASSED), trustedPushPorts($repository, $store, $github));

    expect(trustedPushRan($plan))->toBe(['src/Money.php']);
});

it('passes the verdict of a trusted push at its head, whatever it ran, and that stands for the next push', function (Closure $change): void {
    [$repository, $store, $github, , $head] = trustedPushMerged($change);
    $ports = trustedPushPorts($repository, $store, $github);
    $plan = trustedPushPlan($repository, Mode::since(Mode::LAST_PASSED), $ports);

    $judged = trustedPushJudged($repository, $plan, $ports);
    $main = $store->read(Scope::branch('main'));
    $passed = $main instanceof Ledger ? $main->runs()->passed() : $main;

    expect($judged->verdict->judgement())->toBe(Judgement::Passed)
        ->and($passed instanceof Passed ? $passed->commit()->name() : $passed)->toBe($head);

    $repository->write('src/Tax.php', "<?php\n\nfinal class Tax\n{\n}\n")->commit('The next push.');
    $next = trustedPushPlan($repository, Mode::since(Mode::LAST_PASSED), trustedPushPorts($repository, $store, NotGiven::value()));

    expect(trustedPushRan($next))->toBe(['src/Tax.php'])
        ->and(trustedPushCarried($next))->toBe(['src/Equals.php', 'src/Held.php', 'src/Money.php']);
})->with(['a source file' => [$equals], 'a test' => [$test], 'a file that decides every unit' => [$manifest]]);

it('finds the pass a pull request\'s delivery left, handed through fetch, with nothing of its proofs', function () use ($test): void {
    $repository = trustedPushProject();
    $disk = FetchedCopies::of(
        LedgerDirectory::at(sprintf('%s/.mutation-gate/ledger', $repository->root)),
        LedgerDirectory::at(sprintf('%s/.mutation-gate/fetched', $repository->root)),
        Copies::Newest,
    );
    [, $store, $github] = trustedPushMergedIn($repository, $disk, $test);

    $plan = trustedPushPlan($repository, Mode::since(Mode::LAST_PASSED), trustedPushPorts($repository, $store, $github));
    $read = $store->read(Scope::pullRequest(7));

    expect(trustedPushRan($plan))->toBe([])
        ->and($read instanceof Ledger ? count([...$read->proofs()]) : $read)->toBe(0);
});
