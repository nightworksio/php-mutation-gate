<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
});

it('puts nothing before a mutant\'s run where the gate names no directory for warm workers', function (): void {
    expect(Pool::prefix(1))->toBe([]);
});

it('starts its place\'s worker once, and puts the client asking it before each mutant\'s run', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $directory = WarmDirectory::made($results);
    putenv(sprintf('%s=%s', GateVariable::Warm->value, $directory));
    $first = Pool::prefix(1);
    $worker = file_get_contents(Seat::at($directory, 1)->pid());
    $second = Pool::prefix(1);
    $again = file_get_contents(Seat::at($directory, 1)->pid());
    $listed = new Process(['ps', '-ww', '-o', 'args=', '-p', (string) $worker]);
    $listed->run();
    $clearing = hrtime(as_number: true);
    $warnings = WarmDirectory::cleared($results);
    $cleared = (hrtime(as_number: true) - $clearing) / Seconds::NANOSECONDS;

    expect($first)->toBe([PHP_BINARY, ThisPackage::script('mutation-gate-pest-client'), $directory, '1'])
        ->and($second)->toBe($first)
        ->and($again)->toBe($worker)
        ->and(trim($listed->getOutput()))->toBe(implode(' ', [
            PHP_BINARY,
            ThisPackage::script('mutation-gate-pest-worker'),
            $directory,
            '1',
            (string) getmypid(),
        ]))
        ->and($warnings)->toEqual(Warnings::of())
        ->and($cleared)->toBeLessThan(Seat::WAIT / 2);
});
