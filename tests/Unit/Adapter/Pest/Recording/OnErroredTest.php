<?php

declare(strict_types=1);

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

it('names a test that errored in a mutant\'s own process, by its id', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $events = new Facade();
    Killers::listening($results, '/tmp/mutations/abc', $events, original: false, loaded: Loaded::of([]));

    PhpUnitEvents::errored($events);

    $line = json_decode((string) file_get_contents($results), associative: true);

    expect($line)->toMatchArray(['event' => 'errored', 'mutated' => '/tmp/mutations/abc'])
        ->and(is_array($line) ? $line['test'] : '')
        ->toStartWith('P\\Tests\\Unit\\Adapter\\Pest\\Recording\\OnErroredTest::__pest_evaluable_it_names_a_test');
});
