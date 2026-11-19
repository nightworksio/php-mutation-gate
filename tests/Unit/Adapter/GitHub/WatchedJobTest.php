<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactAccess;
use NightWorksIO\MutationGate\Adapter\GitHub\WatchedJob;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\RuntimeToken;

const WATCHED_LIST = 'https://results.example/twirp/github.actions.results.api.v1.ArtifactService/ListArtifacts';

$environment = static fn(string $watched): Variables => Variables::of([
    'MUTATION_GATE_WATCH_JOBS' => $watched,
    'GITHUB_RUN_ATTEMPT' => '2',
]);

$service = static fn(): ArtifactAccess => ArtifactAccess::of(
    RuntimeToken::scoped('Actions.Results:run-1:job-7'),
    'https://results.example/',
);

$listing = static fn(string ...$names): Cloud => new Cloud()->answering(
    WATCHED_LIST,
    200,
    JsonText::compact(['artifacts' => array_map(static fn(string $name): array => ['name' => $name], $names)]),
);

it('stops once the watch job left the artifact of a failed job for this attempt', function () use ($environment, $service, $listing): void {
    $cloud = $listing('mutation-gate-result-2-1', 'mutation-gate-stopped-2-tests and coverage');

    expect(WatchedJob::of($environment('tests and coverage'), $service(), $cloud->exchange())->stopped())
        ->toBe('The job tests and coverage failed, so this shard starts no further item.')
        ->and($cloud->requests[0]['body'])->toBe('{"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7"}');
});

it('runs on while no artifact names a failed job of this attempt, nothing is watched, or nothing can be listed', function (string $watched, string $name, bool $handed) use ($environment, $service, $listing): void {
    $cloud = $listing($name);

    expect(WatchedJob::of($environment($watched), $handed ? $service() : NotGiven::value(), $cloud->exchange())->stopped())
        ->toBe('')
        ->and($cloud->requests)->toHaveCount($watched === 'checks' && $handed ? 1 : 0);
})->with([
    'no failure' => ['checks', 'mutation-gate-result-2-1', true],
    'an earlier attempt failed' => ['checks', 'mutation-gate-stopped-1-checks', true],
    'nothing watched' => ['', 'mutation-gate-stopped-2-checks', true],
    'only blank lines watched' => ["\n \n", 'mutation-gate-stopped-2-checks', true],
    'no service handed' => ['checks', 'mutation-gate-stopped-2-checks', false],
]);

it('runs on where the service refuses the list', function () use ($environment, $service): void {
    $cloud = new Cloud()->answering(WATCHED_LIST, 500, '');

    expect(WatchedJob::of($environment('checks'), $service(), $cloud->exchange())->stopped())->toBe('');
});
