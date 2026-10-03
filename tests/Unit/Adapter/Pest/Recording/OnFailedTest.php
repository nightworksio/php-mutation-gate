<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;

afterEach(function (): void {
    // Killers logs this process's errors to the mutant's own file, as it does in a mutant's own process.
    ini_restore('error_log');
    ini_restore('log_errors');
    Scratch::sweep();
});

it('names a test that failed in a mutant\'s own process, by its id', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $events = new Facade();
    Killers::listening($results, '/tmp/mutations/abc', $events);

    PhpUnitEvents::failed($events);

    $records = KillerFile::taken(KillerFile::beside($results, '/tmp/mutations/abc'), '/tmp/mutations/abc');
    $record = json_decode($records[0] ?? '', associative: true);

    expect($records)->toHaveCount(1)
        ->and($record)->toMatchArray(['event' => 'killed', 'mutated' => '/tmp/mutations/abc'])
        ->and(is_array($record) ? $record['test'] : '')
        ->toStartWith('P\\Tests\\Unit\\Adapter\\Pest\\Recording\\OnFailedTest::__pest_evaluable_it_names_a_test');
});
