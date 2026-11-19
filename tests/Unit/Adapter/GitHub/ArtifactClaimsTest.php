<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactAccess;
use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactClaims;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\ShardPull;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\RuntimeToken;

const CLAIMS_SERVICE = 'https://results.example/twirp/github.actions.results.api.v1.ArtifactService/';

$token = static fn(string $scope = 'Actions.Results:run-1:job-7'): string => RuntimeToken::scoped(sprintf('Actions.GenericRead %s', $scope));

$runtime = static fn(): Variables => Variables::of(['GITHUB_RUN_ATTEMPT' => '2']);

$claims = static fn(Cloud $cloud, Variables $variables, ShardPull $pull = ShardPull::Auto, ArtifactAccess|CannotJudge|NotGiven|false $service = false): ArtifactClaims|CannotJudge => ArtifactClaims::of(
    $service === false ? ArtifactAccess::of($token(), 'https://results.example/') : $service,
    $variables,
    $cloud->exchange(),
    $pull,
    Digest::of('abc'),
    ShardId::of(3),
);

it('claims an item by creating, filling and finalizing an artifact of its name for the run\'s attempt', function () use ($runtime, $claims): void {
    $cloud = new Cloud()
        ->answering(sprintf('%sCreateArtifact', CLAIMS_SERVICE), 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
        ->answering(sprintf('%sFinalizeArtifact', CLAIMS_SERVICE), 200, '{"ok": true, "artifact_id": "9"}');
    $taken = $claims($cloud, $runtime(), ShardPull::Claim);

    expect($taken instanceof ArtifactClaims ? [$taken->pull(), $taken->take(ShardId::of(5)), $taken->finish(ShardId::of(5))] : $taken)
        ->toEqual([ShardPull::Claim, true, ShardId::of(5)])
        ->and($cloud->requests[0]['body'])->toBe('{"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-claim-abc-2-5","version":4}')
        ->and($cloud->requests[1]['headers']['x-ms-blob-type'] ?? '')->toBe('BlockBlob');
});

it('leaves an item another job claimed', function () use ($runtime, $claims): void {
    $cloud = new Cloud()->answering(sprintf('%sCreateArtifact', CLAIMS_SERVICE), 409, '{"code": "already_exists"}');
    $taken = $claims($cloud, $runtime(), ShardPull::Claim);

    expect($taken instanceof ArtifactClaims ? $taken->take(ShardId::of(5)) : $taken)->toBeFalse();
});

it('steals an item no job marked done, leaves one a job did, and marks what it finished', function () use ($runtime, $claims): void {
    $cloud = new Cloud()
        ->answering(sprintf('%sListArtifacts', CLAIMS_SERVICE), 200, '{"artifacts": [{"name": "mutation-gate-done-abc-2-5-1"}, {"name": "mutation-gate-done-abc-2-51-1"}]}')
        ->answering(sprintf('%sCreateArtifact', CLAIMS_SERVICE), 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
        ->answering(sprintf('%sFinalizeArtifact', CLAIMS_SERVICE), 200, '{"ok": true}');
    $taken = $claims($cloud, $runtime(), ShardPull::Steal);

    expect($taken instanceof ArtifactClaims ? [$taken->take(ShardId::of(5)), $taken->take(ShardId::of(6)), $taken->finish(ShardId::of(6))] : $taken)
        ->toEqual([false, true, ShardId::of(6)])
        ->and(array_column($cloud->requests, 'body'))->toContain('{"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-done-abc-2-6-3","version":4}');
});

it('steals no item another job started, marks its own start before it runs one, and under redo takes one another job started', function () use ($runtime, $claims): void {
    $listed = '{"artifacts": [{"name": "mutation-gate-start-abc-2-5-1"}, {"name": "mutation-gate-start-abc-2-51-1"}]}';
    $cloud = static fn(): Cloud => new Cloud()
        ->answering(sprintf('%sListArtifacts', CLAIMS_SERVICE), 200, $listed)
        ->answering(sprintf('%sCreateArtifact', CLAIMS_SERVICE), 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
        ->answering(sprintf('%sFinalizeArtifact', CLAIMS_SERVICE), 200, '{"ok": true}');
    $stealing = $cloud();
    $redoing = $cloud();
    $steals = $claims($stealing, $runtime(), ShardPull::Steal);
    $redoes = $claims($redoing, $runtime(), ShardPull::Redo);

    expect($steals instanceof ArtifactClaims ? [$steals->take(ShardId::of(5)), $steals->take(ShardId::of(6))] : $steals)->toBe([false, true])
        ->and(array_column($stealing->requests, 'body'))->toContain('{"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-start-abc-2-6-3","version":4}')
        ->and($redoes instanceof ArtifactClaims ? [$redoes->take(ShardId::of(5)), $redoes->take(ShardId::of(6))] : $redoes)->toBe([true, true])
        ->and(implode("\n", array_column($redoing->requests, 'body')))->not->toContain('mutation-gate-start');
});

it('says why a steal cannot be told where its start cannot be marked', function () use ($runtime, $claims): void {
    $cloud = new Cloud()
        ->answering(sprintf('%sListArtifacts', CLAIMS_SERVICE), 200, '{"artifacts": []}')
        ->answering(sprintf('%sCreateArtifact', CLAIMS_SERVICE), 500, '{"code": "internal"}');
    $taken = $claims($cloud, $runtime(), ShardPull::Steal);

    expect($taken instanceof ArtifactClaims ? $taken->take(ShardId::of(5)) : $taken)->toBeInstanceOf(CannotJudge::class);
});

it('steals under auto, since no service\'s claims pass the race test', function () use ($runtime, $claims): void {
    $taken = $claims(new Cloud(), $runtime());

    expect($taken instanceof ArtifactClaims ? $taken->pull() : $taken)->toBe(ShardPull::Steal);
});

it('cannot reach claims without a service handed in, or a token that names a run', function (ArtifactAccess|CannotJudge|NotGiven $service, CannotJudge $why) use ($runtime, $claims): void {
    expect($claims(new Cloud(), $runtime(), ShardPull::Auto, $service))->toEqual($why);
})->with([
    'none handed' => [fn(): NotGiven => NotGiven::value(), fn(): CannotJudge => CannotJudge::because('The gate was handed no artifact service to claim items by.')],
    'a handoff that failed' => [fn(): CannotJudge => CannotJudge::because('unread'), fn(): CannotJudge => CannotJudge::because('unread')],
    'no results scope' => [fn(): ArtifactAccess => ArtifactAccess::of('a.e30.b', 'https://results.example/'), fn(): CannotJudge => CannotJudge::because('The runtime token names no run to write artifacts to.')],
    'claims that cannot be decoded' => [fn(): ArtifactAccess => ArtifactAccess::of('a.!!.b', 'https://results.example/'), fn(): CannotJudge => CannotJudge::because('The runtime token names no run to write artifacts to.')],
]);

it('says why a claim cannot be told where the service fails', function () use ($runtime, $claims): void {
    $taken = $claims(new Cloud(failing: 500), $runtime(), ShardPull::Claim);

    expect($taken instanceof ArtifactClaims ? $taken->take(ShardId::of(5)) : $taken)
        ->toEqual(CannotJudge::because('The artifact service answered CreateArtifact with 500.'));
});

it('says why an item cannot be stolen where the service cannot list the run\'s artifacts', function () use ($runtime, $claims): void {
    $taken = $claims(new Cloud(failing: 500), $runtime(), ShardPull::Steal);

    expect($taken instanceof ArtifactClaims ? $taken->take(ShardId::of(5)) : $taken)
        ->toEqual(CannotJudge::because('The artifact service answered ListArtifacts with 500.'));
});

it('says why a claim cannot be told where the service names no upload, or the upload fails', function (string $created, int $uploaded, string $why) use ($runtime, $claims): void {
    $cloud = new Cloud()
        ->answering(sprintf('%sCreateArtifact', CLAIMS_SERVICE), 200, $created)
        ->answering('https://blob.example/upload', $uploaded, '')
        ->unreachable('https://blob.example/unreachable');
    $taken = $claims($cloud, $runtime(), ShardPull::Claim);
    $told = $taken instanceof ArtifactClaims ? $taken->take(ShardId::of(5)) : $taken;

    expect($told instanceof CannotJudge ? $told->why() : $told)->toContain($why)
        ->and(array_column($cloud->requests, 'url'))->not->toContain(sprintf('%sFinalizeArtifact', CLAIMS_SERVICE));
})->with([
    'no upload named' => ['{"ok": true}', 201, 'CreateArtifact'],
    'the upload refused' => ['{"ok": true, "signed_upload_url": "https://blob.example/upload"}', 403, 'the upload'],
    'an upload that cannot be sent' => ['{"ok": true, "signed_upload_url": "https://blob.example/unreachable"}', 201, 'cannot be reached'],
]);
