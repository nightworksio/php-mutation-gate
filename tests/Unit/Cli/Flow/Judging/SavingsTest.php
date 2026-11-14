<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\BadgeDirectory;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$verdictWith = static function (string $project, Timings $timings): Verdict {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withTimings($timings));

    return JudgingRuns::verdictOf(JudgingRuns::judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $store, JudgingRuns::tree(Floor::of(0))),
        JudgingRuns::settings(),
        JudgingRuns::reporting(new ReporterFake()),
    ));
};
$timed = static fn(): Timings => Timings::of(
    Timing::of(Path::of('src/Money.php'), Seconds::of(40.0), 'fake', Instant::at(new DateTimeImmutable('2026-09-01T00:00:00Z'))),
);

it('says what a run saved once a ledger holds timings, and has no history before', function () use ($verdictWith, $timed): void {
    $saved = $verdictWith(Flows::project(), $timed());
    $cold = $verdictWith(Flows::project(), Timings::none());

    expect($saved->account()->savings())->toBeInstanceOf(Savings::class)
        ->and(SavingsText::of($saved, SavingsWindow::standard()->saved($saved->account()->savedSince(Instant::at(new DateTimeImmutable('2020-01-01T00:00:00Z'))))))
        ->toContain('A full one-job run')
        ->and(SavingsText::of($cold, SavingsWindow::standard()->saved($cold->account()->savedSince(Instant::at(new DateTimeImmutable('2020-01-01T00:00:00Z'))))))
        ->toContain('No history yet');
});

it('reads the trend it follows on the default branch, says what the last 30 days saved, and publishes it', function () use ($verdictWith, $timed): void {
    $project = Flows::project();
    Scratch::write($project, sprintf('%s/%s', BadgeDirectory::PATH, Trend::FILE), Trend::none()->json());
    $verdict = $verdictWith($project, $timed());
    $published = sprintf('%s/published', $project);
    $badge = BadgeDirectory::at($published, BadgeColors::defaults(), Flows::HEAD, Flows::setup()->clock);
    $badge->report($verdict);

    expect($verdict->account()->previous())->toBeInstanceOf(Trend::class)
        ->and(file_get_contents(sprintf('%s/%s', $published, BadgeDirectory::SAVINGS)))->not->toContain('no history yet')
        ->and(file_get_contents(sprintf('%s/%s', $published, Trend::FILE)))->toContain('fullRunSeconds');
});
