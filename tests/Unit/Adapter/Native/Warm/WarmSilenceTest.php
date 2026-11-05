<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Warm\WarmSilence;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A silence limit as a job writes it, read back. */
function warmSilenceRead(SilenceLimit|NotGiven $silence): SilenceLimit|NotGiven
{
    return WarmSilence::read(Node::config(Json::object(...WarmSilence::members($silence))->line()));
}

it('reads back the silence limit, its progress file and each test\'s own limit a job wrote, in the form the file tells its tests', function (SilenceLimit $silence): void {
    expect(warmSilenceRead($silence))->toEqual($silence);
})->with([
    'a silence limit alone' => [fn(): SilenceLimit => SilenceLimit::of(Seconds::of(8.0), '/r/1')],
    'a silence limit and a watch' => [fn(): SilenceLimit => SilenceLimit::of(Seconds::of(8.0), '/r/1')->watchingTests(
        TestWatch::of(PerTestLimit::of(4, Seconds::of(2.5)), TestTimes::of(
            TestTime::of(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.25)),
            TestTime::of(TestId::of('7'), Seconds::of(1.0)),
        )),
        TestEvents::NativeResults,
    )],
    'a watch alone, of PHPUnit\'s events' => [fn(): SilenceLimit => SilenceLimit::none('/r/2')->watchingTests(
        TestWatch::of(PerTestLimit::standard(), TestTimes::of(TestTime::of(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.25)))),
        TestEvents::PhpUnitText,
    )],
]);

it('writes nothing where a run has no silence limit, and reads none back', function (): void {
    expect(WarmSilence::members(NotGiven::value()))->toBe([])
        ->and(warmSilenceRead(NotGiven::value()))->toEqual(NotGiven::value());
});

it('reads a form of test events it does not know as the extension\'s lines', function (): void {
    $read = WarmSilence::read(Node::config(
        '{"progress":"/r/1","watched":{"multiple":3,"least":3.0,"times":{"T::a":1.0},"events":"Unknown"}}',
    ));

    expect($read instanceof SilenceLimit ? $read->events() : $read)->toBe(TestEvents::NativeResults);
});
