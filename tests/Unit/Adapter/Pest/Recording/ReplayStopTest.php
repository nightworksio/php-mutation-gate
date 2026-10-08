<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\ReplayStop;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Support\Str;
use PHPUnit\Event\Facade;

afterEach(function (): void {
    Scratch::sweep();
});

it('stops once as many tests have finished as it was told, recording how many and the order it started them in', function (): void {
    $results = sprintf('%s/replay.jsonl', Scratch::directory());
    $events = new Facade();
    $interrupted = 0;
    ReplayStop::listening('2', $results, '/tmp/originals/abc.php', $events, static function () use (&$interrupted): void {
        $interrupted++;
    });
    $test = TestId::of(sprintf(
        'P\\Tests\\Unit\\Adapter\\Pest\\Recording\\ReplayStopTest::%s',
        Str::evaluable('it stops once as many tests have finished as it was told, recording how many and the order it started them in'),
    ));

    foreach (range(1, 3) as $run) {
        PhpUnitEvents::started($events);
        PhpUnitEvents::finished($events);
    }

    expect(file_get_contents($results))->toBe(RecordLine::stopped('/tmp/originals/abc.php', 2, OrderDigest::of($test, $test)->value()))
        ->and($interrupted)->toBe(1);
});

it('stops nothing where it is told no count of one or more, no results file or no copy', function (string|false $after, string $results, string $mutated): void {
    expect(ReplayStop::listening($after, $results, $mutated, new Facade(), static function (): void {
    }))->toBe(Off::Stopping);
})->with([
    'no count' => [false, '/r/replay.jsonl', '/tmp/originals/abc.php'],
    'an empty count' => ['', '/r/replay.jsonl', '/tmp/originals/abc.php'],
    'a count of none' => ['0', '/r/replay.jsonl', '/tmp/originals/abc.php'],
    'no number' => ['two', '/r/replay.jsonl', '/tmp/originals/abc.php'],
    'no results file' => ['2', '', '/tmp/originals/abc.php'],
    'no copy' => ['2', '/r/replay.jsonl', ''],
]);

it('stops nothing where PHPUnit takes no more subscribers', function (): void {
    $sealed = new Facade();
    $sealed->seal();

    expect(ReplayStop::listening('2', '/r/replay.jsonl', '/tmp/originals/abc.php', $sealed, static function (): void {
    }))->toBe(Off::Stopping);
});

it('reads its count, results file and copy from the variables the adapter sets', function (): void {
    $results = sprintf('%s/replay.jsonl', Scratch::directory());
    $events = new Facade();
    $stop = Environment::during([
        GateVariable::StopAfter->value => '1',
        GateVariable::Results->value => $results,
        Recorder::MUTATED => '/tmp/originals/abc.php',
    ], static fn(): ReplayStop|Off => ReplayStop::fromEnvironment($events));

    expect($stop)->toBeInstanceOf(ReplayStop::class)
        ->and(Environment::during([GateVariable::StopAfter->value => null], static fn(): ReplayStop|Off => ReplayStop::fromEnvironment(new Facade())))
        ->toBe(Off::Stopping);
});
