<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;

$holds = [
    'holds:src/Adapter/Pest',
];

// Patched Pest runs each mutant the gate holds back after the others
// (ADR-0079), and reports every mutant as it does when it holds none back.
// Money.php's `adds` is planned before `large`; held back behind it, its
// outcome comes after that of `large`.

/** A mutant's id as a record spells it. */
function heldBackId(string $id): MutantId
{
    $parsed = MutantId::parse($id);

    return $parsed instanceof MutantId ? $parsed : throw new RuntimeException($parsed->why());
}

it('runs a mutant the gate holds back after the others, and reports every mutant as it would unheld', function (): void {
    Library::patched();
    $library = Library::pest(Patching::on(Library::canary()));
    [$addsRecord, $largeRecord] = $library->expected('adds', 'large');
    $adds = heldBackId($addsRecord[0]);
    $large = heldBackId($largeRecord[0]);
    $held = Subsumption::none()->with(Path::of('src/Money.php'), $adds, $large);

    $result = $library->runner()->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large')))
            ->across(Pool::of(ProcessCount::single(), Workers::Fresh))
            ->searching(KillSearch::standard()->holdingBack($held)),
    );
    $native = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : [] as $mutant) {
        $native[$mutant->nativeId()] = $mutant->id()->value();
    }

    $events = [];

    $lines = file(Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl', Library::DIRECTORY)), FILE_IGNORE_NEW_LINES);

    foreach (is_array($lines) ? $lines : [] as $line) {
        $record = json_decode($line, associative: true);
        $event = is_array($record) && is_string($record['event'] ?? null) ? $record['event'] : '';
        $events[$event][] = is_array($record) && is_string($record['id'] ?? null) && array_key_exists($record['id'], $native)
            ? $native[$record['id']]
            : '';
    }

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toEqualCanonicalizing($library->expected('adds', 'large'))
        ->and($events['planned'] ?? [])->toBe([$adds->value(), $large->value()])
        ->and($events['outcome'] ?? [])->toBe([$large->value(), $adds->value()]);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
