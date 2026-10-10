<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\Alerts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$events = static fn(Verdict $verdict): array => array_map(
    static fn(Alert $alert): AlertEvent => $alert->event(),
    iterator_to_array(Alerts::of($verdict), preserve_keys: false),
);
$cannotJudge = static fn(): Verdict => Verdicts::passing()->withCannotJudge(CannotJudge::because('Shard 2 wrote no result.'));

it('alerts when the default branch changes state, and only then', function (
    string $now,
    RunAccount $before,
    array $alerted,
) use ($events, $cannotJudge): void {
    $verdict = match ($now) {
        'failed' => Verdicts::failing(),
        'cannot-judge' => $cannotJudge(),
        default => Verdicts::passing(),
    };

    expect($events($verdict->withAccount($before)))->toBe($alerted);
})->with([
    'a failure after a pass' => ['failed', fn(): RunAccount => Previous::run('passed'), [AlertEvent::Failed]],
    'a failure after a failure' => ['failed', fn(): RunAccount => Previous::run('failed'), []],
    'a failure after an entry that recorded no judgement' => ['failed', fn(): RunAccount => RunAccount::none()->after(Trend::decode('{"runs": [{"commit": "a", "time": "t", "trees": {}}]}')), [AlertEvent::Failed]],
    'a failure with no run before it' => ['failed', fn(): RunAccount => Previous::none(), [AlertEvent::Failed]],
    'cannot judge after a pass' => ['cannot-judge', fn(): RunAccount => Previous::run('passed'), [AlertEvent::CannotJudge]],
    'cannot judge after a failure' => ['cannot-judge', fn(): RunAccount => Previous::run('failed'), [AlertEvent::CannotJudge]],
    'cannot judge again' => ['cannot-judge', fn(): RunAccount => Previous::run('cannot-judge'), []],
    'cannot judge with no run before it' => ['cannot-judge', fn(): RunAccount => Previous::none(), []],
    'a pass after a failure' => ['passed', fn(): RunAccount => Previous::run('failed'), [AlertEvent::Recovered]],
    'a pass after cannot judge' => ['passed', fn(): RunAccount => Previous::run('cannot-judge'), [AlertEvent::Recovered]],
    'a pass after a pass' => ['passed', fn(): RunAccount => Previous::run('passed'), []],
    'a pass with no run before it' => ['passed', fn(): RunAccount => Previous::none(), []],
]);

it('alerts a lowered floor once, beside the change it came with', function () use ($events): void {
    expect($events(Verdicts::passing()->withAccount(Previous::run('passed', floor: 90.0))))->toBe([AlertEvent::FloorLowered])
        ->and($events(Verdicts::passing()->withAccount(Previous::run('failed', floor: 90.0))))->toBe([AlertEvent::Recovered, AlertEvent::FloorLowered])
        ->and($events(Verdicts::passing()->withAccount(Previous::run('passed', floor: 80.0))))->toBe([]);
});

it('alerts nothing off the default branch, or for a run its budget cut short', function () use ($events): void {
    expect($events(Verdicts::failing()))->toBe([])
        ->and($events(Verdicts::failing()->withAccount(Previous::run('passed', floor: 90.0))->cutShort()))->toBe([])
        ->and(Alerts::of(Verdicts::failing()))->toHaveCount(0);
});

it('tells each alert against the newest entry, with the verdict that made it', function (): void {
    $verdict = Verdicts::failing()->withAccount(Previous::run('passed'));
    [$alert] = [...Alerts::of($verdict)];

    expect($alert->verdict())->toBe($verdict)
        ->and($alert->previous()->verdict())->toBe(Judgement::Passed)
        ->and($alert->previous()->floorOf(Path::of('src')))->toEqual(Floor::of(80));
});
