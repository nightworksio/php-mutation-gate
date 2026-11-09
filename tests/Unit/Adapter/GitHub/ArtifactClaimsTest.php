<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactClaims;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\ShardPull;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Tests\Support\Cloud;

const CLAIMS_SERVICE = 'https://results.example/twirp/github.actions.results.api.v1.ArtifactService/';

$runtime = static fn(string $scope = 'Actions.Results:run-1:job-7'): array => [
    'ACTIONS_RUNTIME_TOKEN' => sprintf('header.%s.signature', rtrim(strtr(base64_encode(json_encode(['scp' => sprintf('Actions.GenericRead %s', $scope)], JSON_THROW_ON_ERROR)), '+/', '-_'), '=')),
    'ACTIONS_RESULTS_URL' => 'https://results.example/',
    'GITHUB_SERVER_URL' => 'https://github.com',
    'GITHUB_RUN_ATTEMPT' => '2',
];

$claims = static fn(Cloud $cloud, array $variables, ShardPull $pull = ShardPull::Auto): ArtifactClaims|CannotJudge => ArtifactClaims::of(
    Variables::of($variables),
    $cloud->exchange(),
    $pull,
    Digest::of('abc'),
    ShardId::of(3),
);

it('claims an item by creating, filling and finalizing an artifact of its name for the run\'s attempt', function () use ($runtime, $claims): void {
    $cloud = new Cloud()
        ->answering(CLAIMS_SERVICE . 'CreateArtifact', 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
        ->answering(CLAIMS_SERVICE . 'FinalizeArtifact', 200, '{"ok": true, "artifact_id": "9"}');
    $taken = $claims($cloud, $runtime());

    expect($taken instanceof ArtifactClaims ? [$taken->pull(), $taken->take(ShardId::of(5)), $taken->finish(ShardId::of(5))] : $taken)
        ->toEqual([ShardPull::Claim, true, ShardId::of(5)])
        ->and($cloud->requests[0]['body'])->toBe('{"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-claim-abc-2-5","version":4}')
        ->and($cloud->requests[1]['headers']['x-ms-blob-type'] ?? '')->toBe('BlockBlob');
});

it('leaves an item another job claimed', function () use ($runtime, $claims): void {
    $cloud = new Cloud()->answering(CLAIMS_SERVICE . 'CreateArtifact', 409, '{"code": "already_exists"}');
    $taken = $claims($cloud, $runtime());

    expect($taken instanceof ArtifactClaims ? $taken->take(ShardId::of(5)) : $taken)->toBeFalse();
});

it('steals an item no job marked done, leaves one a job did, and marks what it finished', function () use ($runtime, $claims): void {
    $cloud = new Cloud()
        ->answering(CLAIMS_SERVICE . 'ListArtifacts', 200, '{"artifacts": [{"name": "mutation-gate-done-abc-2-5-1"}, {"name": "mutation-gate-done-abc-2-51-1"}]}')
        ->answering(CLAIMS_SERVICE . 'CreateArtifact', 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
        ->answering(CLAIMS_SERVICE . 'FinalizeArtifact', 200, '{"ok": true}');
    $taken = $claims($cloud, $runtime(), ShardPull::Steal);

    expect($taken instanceof ArtifactClaims ? [$taken->take(ShardId::of(5)), $taken->take(ShardId::of(6)), $taken->finish(ShardId::of(6))] : $taken)
        ->toEqual([false, true, ShardId::of(6)])
        ->and(array_column($cloud->requests, 'body'))->toContain('{"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-done-abc-2-6-3","version":4}');
});

it('steals on a service the race test has not passed on', function () use ($runtime, $claims): void {
    $taken = $claims(new Cloud(), [...$runtime(), 'GITHUB_SERVER_URL' => 'https://ghe.example.com']);

    expect($taken instanceof ArtifactClaims ? $taken->pull() : $taken)->toBe(ShardPull::Steal);
});

it('cannot reach claims without the runtime token, the service, or a results scope', function (array $variables, string $why) use ($runtime, $claims): void {
    expect($claims(new Cloud(), [...$runtime(), ...$variables]))->toEqual(CannotJudge::because($why));
})->with([
    'no token' => [['ACTIONS_RUNTIME_TOKEN' => ''], 'The artifact service is not reachable from this step: ACTIONS_RUNTIME_TOKEN is not set.'],
    'no service' => [['ACTIONS_RESULTS_URL' => ''], 'The artifact service is not reachable from this step: ACTIONS_RESULTS_URL is not set.'],
    'no scope' => [['ACTIONS_RUNTIME_TOKEN' => 'a.e30.b'], 'The runtime token names no run to write artifacts to.'],
]);

it('says why a claim cannot be told where the service fails', function () use ($runtime, $claims): void {
    $taken = $claims(new Cloud(failing: 500), $runtime());

    expect($taken instanceof ArtifactClaims ? $taken->take(ShardId::of(5)) : $taken)
        ->toEqual(CannotJudge::because('The artifact service answered CreateArtifact with 500.'));
});
