<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactService;
use NightWorksIO\MutationGate\Adapter\GitHub\WatchedJob;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$environment = static fn(string $watched): Variables => Variables::of([
    'MUTATION_GATE_WATCH_JOB' => $watched,
    'GITHUB_API_URL' => 'https://api.github.example',
    'GITHUB_REPOSITORY' => 'acme/shop',
    'GITHUB_RUN_ID' => '42',
    'GITHUB_RUN_ATTEMPT' => '1',
]);

$jobs = static fn(array ...$jobs): MockHttpClient => new MockHttpClient(new MockResponse(json_encode(['jobs' => $jobs], JSON_THROW_ON_ERROR)));

it('stops once the watched job concluded in failure', function () use ($environment, $jobs): void {
    $watched = WatchedJob::of(
        $environment('tests and coverage'),
        ArtifactService::of('runtime', 'https://results.example/', 'ghs_job'),
        $jobs(['name' => 'tests and coverage', 'conclusion' => 'failure'], ['name' => 'lint', 'conclusion' => 'success']),
    );

    expect($watched->stopped())->toBe('The job tests and coverage failed, so this shard starts no further item.');
});

it('runs on while the watched job has not failed, another job failed, or nothing is watched or can be read', function (string $watched, array $job, bool $handed) use ($environment, $jobs): void {
    expect(WatchedJob::of(
        $environment($watched),
        $handed ? ArtifactService::of('runtime', 'https://results.example/', 'ghs_job') : NotGiven::value(),
        $jobs($job),
    )->stopped())->toBe('');
})->with([
    'still running' => ['tests and coverage', ['name' => 'tests and coverage', 'conclusion' => null], true],
    'another job failed' => ['tests and coverage', ['name' => 'lint', 'conclusion' => 'failure'], true],
    'nothing watched' => ['', ['name' => 'tests and coverage', 'conclusion' => 'failure'], true],
    'no token handed' => ['tests and coverage', ['name' => 'tests and coverage', 'conclusion' => 'failure'], false],
]);
