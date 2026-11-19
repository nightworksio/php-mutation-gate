<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactAccess;
use NightWorksIO\MutationGate\Adapter\GitHub\ProgressArtifacts;
use NightWorksIO\MutationGate\Adapter\GitHub\RunArtifacts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\StoredZip;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\RuntimeToken;

const PROGRESS_SERVICE = 'https://results.example/twirp/github.actions.results.api.v1.ArtifactService/';

$left = static function (Cloud $cloud): ProgressArtifacts|CannotJudge {
    $artifacts = RunArtifacts::of(
        ArtifactAccess::of(RuntimeToken::scoped('Actions.Results:run-1:job-7'), 'https://results.example/'),
        $cloud->exchange(),
    );

    return $artifacts instanceof RunArtifacts
        ? ProgressArtifacts::of($artifacts, Digest::of('abc'), 2, ShardId::of(5))
        : $artifacts;
};

$serving = static fn(): Cloud => new Cloud()
    ->answering(sprintf('%sCreateArtifact', PROGRESS_SERVICE), 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
    ->answering(sprintf('%sFinalizeArtifact', PROGRESS_SERVICE), 200, '{"ok": true}')
    ->answering(sprintf('%sDeleteArtifact', PROGRESS_SERVICE), 200, '{"ok": true}');

$bodies = static fn(Cloud $cloud): array => array_map(
    static fn(array $request): string => sprintf('%s %s', $request['url'], $request['body']),
    $cloud->requests,
);

it('leaves each progress in an artifact of its own, numbered, holding it in a zip, and deletes the one before it', function () use ($left, $serving, $bodies): void {
    $cloud = $serving();
    $progress = $left($cloud);

    expect($progress instanceof ProgressArtifacts ? [$progress->leave('first'), $progress->leave('second'), $progress->clear()] : $progress)->toBe([true, true, true])
        ->and($bodies($cloud))->toBe([
            sprintf('%sCreateArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-2-5-1","version":4}', PROGRESS_SERVICE),
            sprintf('https://blob.example/upload %s', StoredZip::of(['progress.txt' => 'first'])),
            sprintf('%sFinalizeArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-2-5-1","size":"%d","hash":"sha256:%s"}', PROGRESS_SERVICE, strlen(StoredZip::of(['progress.txt' => 'first'])), hash('sha256', StoredZip::of(['progress.txt' => 'first']))),
            sprintf('%sCreateArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-2-5-2","version":4}', PROGRESS_SERVICE),
            sprintf('https://blob.example/upload %s', StoredZip::of(['progress.txt' => 'second'])),
            sprintf('%sFinalizeArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-2-5-2","size":"%d","hash":"sha256:%s"}', PROGRESS_SERVICE, strlen(StoredZip::of(['progress.txt' => 'second'])), hash('sha256', StoredZip::of(['progress.txt' => 'second']))),
            sprintf('%sDeleteArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-2-5-1"}', PROGRESS_SERVICE),
            sprintf('%sDeleteArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-2-5-2"}', PROGRESS_SERVICE),
        ]);
});

it('deletes nothing where it left nothing', function () use ($left, $serving): void {
    $cloud = $serving();

    $progress = $left($cloud);

    expect($progress instanceof ProgressArtifacts ? $progress->clear() : $progress)->toBeTrue()
        ->and($cloud->requests)->toBe([]);
});

it('says why a progress was not left: its name taken, or the service failing, and leaves the next under the same number', function () use ($left): void {
    $taken = new Cloud()->answering(sprintf('%sCreateArtifact', PROGRESS_SERVICE), 409, '{"code": "already_exists"}');
    $failing = $left(new Cloud(failing: 500));
    $refused = $left($taken);

    expect($refused instanceof ProgressArtifacts ? $refused->leave('first') : $refused)->toEqual(CannotJudge::because('Another artifact of the run is named mutation-gate-progress-abc-2-5-1.'))
        ->and($failing instanceof ProgressArtifacts ? [$failing->leave('first'), $failing->clear()] : $failing)
        ->toEqual([CannotJudge::because('The artifact service answered CreateArtifact with 500.'), true]);
});

it('says why the artifact before was not deleted, the new one standing', function () use ($left): void {
    $cloud = new Cloud()
        ->answering(sprintf('%sCreateArtifact', PROGRESS_SERVICE), 200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}')
        ->answering(sprintf('%sFinalizeArtifact', PROGRESS_SERVICE), 200, '{"ok": true}')
        ->answering(sprintf('%sDeleteArtifact', PROGRESS_SERVICE), 404, '{"code": "not_found"}');
    $progress = $left($cloud);

    expect($progress instanceof ProgressArtifacts ? [$progress->leave('first'), $progress->leave('second')] : $progress)
        ->toEqual([true, CannotJudge::because('The artifact service answered DeleteArtifact with 404.')]);
});
