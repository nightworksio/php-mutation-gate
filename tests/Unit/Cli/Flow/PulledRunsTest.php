<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactAccess;
use NightWorksIO\MutationGate\Adapter\GitHub\WatchedJob;
use NightWorksIO\MutationGate\Cli\Flow\PulledRuns;
use NightWorksIO\MutationGate\Cli\Flow\ShardsLeft;
use NightWorksIO\MutationGate\Config\Shards as ShardsSetting;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\RuntimeToken;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;

const PULLED_SERVICE = 'https://results.example/twirp/github.actions.results.api.v1.ArtifactService/';

const PULLED_AT = '2026-11-15T00:00:00Z';

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A plan of two items whose shards pull their work, each expected to take this long, the first listed for job 1 and
 * the second for job 2.
 */
function pulledPlan(float $each = 1.0): Plan
{
    $item = static fn(int $number): Shard => Shard::of(
        ShardId::of($number),
        Package::at(Path::root()),
        Units::of(Unit::file(Path::of(sprintf('src/%d.php', $number)))),
        Seconds::of($each),
        'src',
    )->workedBy(ShardId::of($number));

    return Plan::of(Revision::ref('HEAD'), Digest::sha256Of('pulled'), Keys::none(), Shards::of($item(1), $item(2)));
}

/**
 * What job 1 left of the items it took through this cloud, watching a job or none, and the items it ran, its deadline
 * this many seconds after the clock's moment, each item expected to take this long; where the results are blocked, a
 * file stands where they go.
 *
 * @return array{ShardsLeft|CannotJudge, list<int>}
 */
function pulledBy(
    Cloud $cloud,
    string $watching = '',
    bool $blocked = false,
    float $deadlineIn = 60.0,
    float $each = 1.0,
    ShardsSetting|NotGiven $pull = new NotGiven(),
): array {
    $project = Scratch::directory();

    if ($blocked) {
        Scratch::write($project, 'results', 'a file where the results go');
    }

    $adapters = Flows::adapters($project, ['GITHUB_RUN_ATTEMPT' => '1', WatchedJob::NAME => $watching]);
    $service = ArtifactAccess::of(RuntimeToken::scoped('Actions.GenericRead Actions.Results:run-1:job-7'), 'https://results.example/');
    $ran = [];

    $at = new DateTimeImmutable(PULLED_AT);
    $left = new PulledRuns($adapters, Flows::settings(...$pull instanceof ShardsSetting ? [$pull] : []), $cloud->exchange(), new StoppedClock(PULLED_AT))->left(
        pulledPlan($each),
        ShardId::of(1),
        Path::of('results'),
        $service,
        Deadline::after($at, Seconds::of($deadlineIn)),
        static function (ShardId $item) use (&$ran, $at): ShardResult {
            $ran[] = $item->number();

            return ShardResult::of(
                Digest::sha256Of('pulled'),
                $item,
                Keys::none(),
                MutationResult::skippingNone(Mutants::of()),
                Measurement::of(Seconds::of(1.0), 'fake', Instant::at($at)),
            );
        },
    );

    return [$left, $ran];
}

it('starts no item once a job it watches failed, and says which', function (): void {
    $cloud = new Cloud()->answering(sprintf('%sListArtifacts', PULLED_SERVICE), 200, '{"artifacts": [{"name": "mutation-gate-stopped-1-checks"}]}');

    [$left, $ran] = pulledBy($cloud, watching: "checks\n");

    expect($left instanceof ShardsLeft ? $left->said() : $left)->toContain('The job checks failed, so this shard starts no further item.')
        ->and($ran)->toBe([]);
});

it('starts no item once its deadline passed, and says so', function (float $deadlineIn, array $items, bool $said): void {
    [$left, $ran] = pulledBy(new Cloud(failing: 500), deadlineIn: $deadlineIn);

    expect($left instanceof ShardsLeft && str_contains($left->said(), sprintf(PulledRuns::PAST, 1)))->toBe($said)
        ->and($ran)->toBe($items);
})->with([
    'passed' => [-1.0, [], true],
    'at the moment' => [0.0, [], true],
    'ahead' => [1.0, [1], false],
]);

it('takes a further item only where the wall time the plan expects of it fits the time left', function (float $each, array $items, bool $said): void {
    $cloud = new Cloud()
        ->answering(sprintf('%sListArtifacts', PULLED_SERVICE), 200, '{"artifacts": []}')
        ->answering(sprintf('%sCreateArtifact', PULLED_SERVICE), 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
        ->answering(sprintf('%sFinalizeArtifact', PULLED_SERVICE), 200, '{"ok": true}');

    [$left, $ran] = pulledBy($cloud, deadlineIn: 60.0, each: $each);

    expect($left instanceof ShardsLeft && str_contains($left->said(), sprintf(PulledRuns::UNFIT, 1, 2, (int) ceil($each), 60)))->toBe($said)
        ->and($ran)->toBe($items);
})->with([
    'fitting' => [59.5, [1, 2], false],
    'as long as the time left' => [60.0, [1, 2], false],
    'past it, its own item taken all the same' => [60.5, [1], true],
]);

it('runs its own items alone, saying why, where it cannot claim one', function (): void {
    [$left, $ran] = pulledBy(new Cloud(failing: 500));

    expect($left instanceof ShardsLeft ? $left->said() : $left)->toContain('ListArtifacts with 500')
        ->and($ran)->toBe([1]);
});

it('runs its own items alone, saying why, once it cannot mark one it started or one it finished', function (ShardsSetting $pull): void {
    $cloud = new Cloud()
        ->answering(sprintf('%sListArtifacts', PULLED_SERVICE), 200, '{"artifacts": []}')
        ->answering(sprintf('%sCreateArtifact', PULLED_SERVICE), 500, '');

    [$left, $ran] = pulledBy($cloud, pull: $pull);

    expect($left instanceof ShardsLeft ? $left->said() : $left)->toContain('CreateArtifact with 500')
        ->and($ran)->toBe([1])
        ->and(array_values(array_filter(
            array_column($cloud->requests, 'url'),
            static fn(string $url): bool => str_ends_with($url, 'CreateArtifact'),
        )))->toHaveCount(1);
})->with([
    'a start, stealing' => [fn(): ShardsSetting => ShardsSetting::pullingBySteals()],
    'a finish, redoing' => [fn(): ShardsSetting => ShardsSetting::pullingByRedoing()],
]);

it('cannot leave an item\'s result where the results cannot be written', function (): void {
    [$left] = pulledBy(new Cloud(failing: 500), blocked: true);

    expect($left)->toBeInstanceOf(CannotJudge::class);
});
