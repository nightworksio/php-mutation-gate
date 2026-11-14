<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ParentLines;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Recorded;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Tests\Fakes\LineCollectorFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$planned = static fn(string $recording): StreamPlan => new StreamPlan(
    sprintf('%s/stream.jsonl', Scratch::directory()),
    [],
    [],
    [],
    1,
    new ChildLimit(0.0, 0.0, 0.0),
    $recording,
);

it('records the lines each test of the parent executed, one run in its own process as none, and every line its tests loaded once the run ends', function () use ($planned): void {
    $recording = sprintf('%s/lines.jsonl', Scratch::directory());
    $lines = new ParentLines($planned($recording), new Recorded(new LineCollectorFake([
        ['/project/src/Money.php' => [11 => 1, 12 => -1]],
        ['/project/src/Money.php' => [12 => 1], '/project/src/Shapes.php' => [3 => -1]],
    ])));

    $lines->began(parent: true);
    $lines->ended('MoneyTest::adds', passed: true, troubled: false, seconds: 0.5);
    $lines->began(parent: true);
    $lines->isolated('MoneyTest::splits');
    $lines->ended('MoneyTest::splits', passed: false, troubled: true, seconds: 0.25);
    $lines->finished();

    expect(file_get_contents($recording))->toBe(implode('', [
        StreamLine::covered('MoneyTest::adds', ['/project/src/Money.php' => [11]], new TestEnded(passed: true, troubled: false, own: false, seconds: 0.5)),
        StreamLine::covered('MoneyTest::splits', [], new TestEnded(passed: false, troubled: false, own: true, seconds: 0.0)),
        StreamLine::covered('MoneyTest::splits', ['/project/src/Money.php' => [12]], new TestEnded(passed: false, troubled: true, own: true, seconds: 0.25)),
        StreamLine::finished(['/project/src/Money.php' => [11, 12], '/project/src/Shapes.php' => [3]]),
    ]));
});

it('records nothing in a child, nor where the plan records no lines', function () use ($planned): void {
    $recording = sprintf('%s/lines.jsonl', Scratch::directory());
    $collector = new LineCollectorFake();
    $child = new ParentLines($planned($recording), new Recorded($collector));
    $unrecorded = new ParentLines($planned(''), new Recorded($collector));

    foreach ([$child, $unrecorded] as $lines) {
        $lines->began(parent: $lines === $unrecorded);
        $lines->isolated('MoneyTest::adds');
        $lines->ended('MoneyTest::adds', passed: true, troubled: false, seconds: 0.5);
    }

    $unrecorded->finished();

    expect($recording)->not->toBeFile()
        ->and($collector->calls())->toBe([]);
});
