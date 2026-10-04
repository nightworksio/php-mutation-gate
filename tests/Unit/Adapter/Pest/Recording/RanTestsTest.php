<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;

afterEach(function (): void {
    // Killers logs this process's errors to the mutant's own file, as it does in a mutant's own process.
    ini_restore('error_log');
    ini_restore('log_errors');
    Scratch::sweep();
});

it('writes how many tests a mutant\'s own process ran to its killer file once PHPUnit ends its run, and nothing before', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $killers = KillerFile::beside($results, '/tmp/mutations/abc');
    $events = new Facade();
    Killers::listening($results, '/tmp/mutations/abc', $events, original: false, loaded: Loaded::of([]));

    PhpUnitEvents::finished($events);
    PhpUnitEvents::finished($events);
    $before = is_file($killers);
    PhpUnitEvents::executionFinished($events);

    expect($before)->toBeFalse()
        ->and(file_get_contents($killers))->toBe(KillerFile::ran(2))
        ->and(is_file($results))->toBeFalse();
});

it('writes that a run its filter selected no test for ran none', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $events = new Facade();
    Killers::listening($results, '/tmp/mutations/abc', $events, original: false, loaded: Loaded::of([]));

    PhpUnitEvents::executionFinished($events);

    expect(file_get_contents(KillerFile::beside($results, '/tmp/mutations/abc')))->toBe(KillerFile::ran(0));
});
