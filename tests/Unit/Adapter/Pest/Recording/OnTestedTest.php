<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnTested;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\Test\Outcome\Tested;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    Scratch::sweep();
});

it('records a mutant a test caught as the event arrives', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $test = Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Tested);

    new OnTested(Mutations::recorder($results, '/c'))->notify(new Tested($test));

    expect(Mutations::recorded($results))->toBe([RecordLine::outcome('id-1', PestStatus::Tested)]);
});

it('records the memory limit a caught mutant\'s own process logged it ran out of, a fatal error PHP records, and removes the log', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $log = Recorder::errorsBeside($results, '/m/id-1.php');
    file_put_contents($log, "[30-Sep-2026 21:50:26 UTC] PHP Fatal error:  Allowed memory size of 67108864 bytes exhausted\n");

    new OnTested(Mutations::recorder($results, '/c'))
        ->notify(new Tested(Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Tested, '/m/id-1.php')));

    expect(Mutations::recorded($results))->toBe([
        RecordLine::outcome('id-1', PestStatus::Tested),
        RecordLine::exhausted('/m/id-1.php', MemoryCap::of(64, MemoryUnit::Megabytes)),
        RecordLine::fatal('/m/id-1.php'),
    ])->and(is_file($log))->toBeFalse();
});

it('records no memory limit for a mutant whose log holds none, or that logged nothing', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $log = Recorder::errorsBeside($results, '/m/id-1.php');
    file_put_contents($log, "PHP Warning:  Undefined variable \$x\n");
    $recorder = Mutations::recorder($results, '/c');

    new OnTested($recorder)->notify(new Tested(Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Tested, '/m/id-1.php')));
    new OnTested($recorder)->notify(new Tested(Mutations::test('/p/src/Money.php', 'id-2', MutationTestResult::Tested, '/m/id-2.php')));

    expect(Mutations::recorded($results))->toBe([
        RecordLine::outcome('id-1', PestStatus::Tested),
        RecordLine::outcome('id-2', PestStatus::Tested),
    ])->and(is_file($log))->toBeFalse();
});

it('records a fatal error PHP logged in a caught mutant\'s own process, and no memory limit where it names none', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $log = Recorder::errorsBeside($results, '/m/id-1.php');
    file_put_contents($log, "[07-Oct-2026 20:15:01 UTC] PHP Fatal error:  Cannot redeclare function helper() in /p/src/helpers.php on line 9\n");

    new OnTested(Mutations::recorder($results, '/c'))
        ->notify(new Tested(Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Tested, '/m/id-1.php')));

    expect(Mutations::recorded($results))->toBe([
        RecordLine::outcome('id-1', PestStatus::Tested),
        RecordLine::fatal('/m/id-1.php'),
    ])->and(is_file($log))->toBeFalse();
});
