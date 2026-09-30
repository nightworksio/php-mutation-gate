<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnTested;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\Test\Outcome\Tested;
use Pest\Mutate\Support\MutationTestResult;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('records a mutant a test caught as the event arrives', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $test = Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Tested);

    new OnTested(Mutations::recorder($results, '/c'))->notify(new Tested($test));

    expect(Mutations::recorded($results))->toBe([RecordLine::outcome('id-1', PestStatus::Tested)]);
});

it('records the memory limit a caught mutant\'s own process ran out of, as its output says, on either stream', function (
    string $script,
): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->run();
    $test = Mutations::ran(Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Tested, '/m/id-1.php'), $process);

    new OnTested(Mutations::recorder($results, '/c'))->notify(new Tested($test));

    expect(Mutations::recorded($results))->toBe([
        RecordLine::outcome('id-1', PestStatus::Tested),
        RecordLine::exhausted('/m/id-1.php', MemoryCap::of(64, MemoryUnit::Megabytes)),
    ]);
})->with([
    'on its output' => ['echo "Fatal error: Allowed memory size of 67108864 bytes exhausted"; exit(255);'],
    'on its errors' => ['fwrite(STDERR, "PHP Fatal error:  Allowed memory size of 67108864 bytes exhausted"); exit(255);'],
]);

it('records no memory limit for a mutant whose process said nothing of one, or that Pest never started', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $process = new Process([PHP_BINARY, '-r', 'echo "Tests: 1 failed"; exit(1);']);
    $process->run();
    $recorder = Mutations::recorder($results, '/c');

    new OnTested($recorder)->notify(new Tested(Mutations::ran(Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Tested), $process)));
    new OnTested($recorder)->notify(new Tested(Mutations::test('/p/src/Money.php', 'id-2', MutationTestResult::Tested)));

    expect(Mutations::recorded($results))->toBe([
        RecordLine::outcome('id-1', PestStatus::Tested),
        RecordLine::outcome('id-2', PestStatus::Tested),
    ]);
});
