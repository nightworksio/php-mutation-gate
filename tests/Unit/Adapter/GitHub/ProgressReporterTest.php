<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactAccess;
use NightWorksIO\MutationGate\Adapter\GitHub\ProgressReporter;
use NightWorksIO\MutationGate\Adapter\Process\ErrorOutputLog;
use NightWorksIO\MutationGate\Adapter\Process\KeptLog;
use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Runner\ProcessUse;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\GaugeFake;
use NightWorksIO\MutationGate\Tests\Support\RuntimeToken;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv(ProcessLog::KEPT);
    Scratch::sweep();
});

/** An exchange that writes the method each request calls, or the blob it uploads, as a line of a file, and accepts it. */
function progressExchange(string $file): Exchange
{
    return new readonly class ($file) implements Exchange {
        public function __construct(private string $file)
        {
        }

        public function forked(): Exchange
        {
            return $this;
        }

        public function answer(Request $request): Reply
        {
            $method = basename($request->url());
            $said = $method === 'upload' ? sprintf('upload %s', $request->body()) : sprintf('%s %s', $method, $request->body());
            file_put_contents($this->file, sprintf("%s\n", str_replace("\n", '|', $said)), FILE_APPEND);

            return Reply::answered(200, '{"ok": true, "signed_upload_url": "https://blob.example/upload"}');
        }

        public function fetch(Request $request, LedgerLimits $limits, string $from): string
        {
            return '';
        }

        public function put(Request $request, LedgerLimits $limits, string $to): Written
        {
            return Written::to($to);
        }
    };
}

$reporter = static fn(string $calls, Seconds $every, ArtifactAccess|CannotJudge|NotGiven|null $service = null): ProgressReporter => ProgressReporter::startedWith(
    $every,
    $service ?? ArtifactAccess::of(RuntimeToken::scoped('Actions.Results:run-1:job-7'), 'https://results.example/'),
    Variables::of(['GITHUB_RUN_ATTEMPT' => '1']),
    Digest::of('abc'),
    ShardId::of(3),
    new SystemClock(),
    GaugeFake::silent(),
    progressExchange($calls),
    static fn(array $command): string => $command === ProcessUse::LISTING ? "  1     0   100 00:01 init\n" : '',
);

$calls = static function (string $file, string $until): array {
    for ($look = 0; $look < 200 && (!is_file($file) || !str_contains((string) file_get_contents($file), $until)); $look++) {
        Running::awaitAny(Seconds::of(0.025));
        clearstatcache();
    }

    return is_file($file) ? explode("\n", trim((string) file_get_contents($file))) : [];
};

it('leaves the shard\'s progress at once and then every so long, with the lines the gate said, and deletes the last once stopped', function () use ($reporter, $calls): void {
    $file = sprintf('%s/calls', Scratch::directory());
    $progress = $reporter($file, Seconds::of(0.3));
    KeptLog::over(ErrorOutputLog::to(sprintf('%s/log', Scratch::directory())))->say('judging src/A.php:3');
    $seen = $calls($file, 'progress-abc-1-3-2"');
    $progress->stop();
    $done = $calls($file, 'DeleteArtifact');

    expect(array_values(array_filter($seen, static fn(string $call): bool => str_starts_with($call, 'CreateArtifact'))))->toContain(
        'CreateArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-1-3-1","version":4}',
        'CreateArtifact {"workflow_run_backend_id":"run-1","workflow_job_run_backend_id":"job-7","name":"mutation-gate-progress-abc-1-3-2","version":4}',
    )->and(implode("\n", $seen))->toContain('judging src/A.php:3')
        ->and(implode("\n", $seen))->toContain('mutation-gate: shard 3, ')
        ->and(implode("\n", $seen))->toContain('0 MiB  00:01  pid 1 of 0  init')
        ->and(end($done))->toStartWith('DeleteArtifact')
        ->and(getenv(ProcessLog::KEPT))->toBeFalse();
})->skip(fn(): bool => ! Forking::isAvailable(), 'the reporter forks');

it('leaves no progress without a service, with a progress of nothing, or where the token names no run', function (ArtifactAccess|CannotJudge|NotGiven $service, Seconds $every) use ($reporter): void {
    $file = sprintf('%s/calls', Scratch::directory());
    $progress = $reporter($file, $every, $service);
    Running::awaitAny(Seconds::of(0.1));
    $progress->stop();

    expect(is_file($file))->toBeFalse()
        ->and(getenv(ProcessLog::KEPT))->toBeFalse();
})->with([
    'no service' => [fn(): NotGiven => NotGiven::value(), fn(): Seconds => Seconds::of(0.1)],
    'a handoff that failed' => [fn(): CannotJudge => CannotJudge::because('unread'), fn(): Seconds => Seconds::of(0.1)],
    'no progress' => [fn(): ArtifactAccess => ArtifactAccess::of(RuntimeToken::scoped('Actions.Results:run-1:job-7'), 'https://results.example/'), fn(): Seconds => Seconds::of(0)],
    'no run in the token' => [fn(): ArtifactAccess => ArtifactAccess::of('a.e30.b', 'https://results.example/'), fn(): Seconds => Seconds::of(0.1)],
])->skip(fn(): bool => ! Forking::isAvailable(), 'the reporter forks');
