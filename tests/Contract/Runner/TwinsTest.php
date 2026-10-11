<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\ControlStructures\WhileAlwaysFalse;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use Pest\Mutate\Mutators\Removal\RemoveArrayItem;

$holds = [
    'holds:src/Adapter/Pest',
];

// Patched Pest runs one of the mutants that leave a file alike, and the
// adapter judges the rest by that run (ADR-0025, decision 13). Looped.php
// holds two such pairs: `while (true)` made `while (false)` by two mutators,
// and either item of `['x', 'x']` removed.

it('runs one of each pair of mutants that leave a file alike, and judges the other as it', function (): void {
    $source = Tree::at(sprintf('%s/src/Looped.php', Library::DIRECTORY));
    $test = Tree::at(sprintf('%s/tests/LoopedSpec.php', Library::DIRECTORY));
    copy(Tree::at('tests/Contract/Runner/twins/src/Looped.php'), $source);
    copy(Tree::at('tests/Contract/Runner/twins/tests/LoopedSpec.php'), $test);
    Patch::applyIn(Library::vendor());

    try {
        $result = Library::pest(Patching::on(Library::canary()))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Looped.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Looped.php')),
                Narrowing::none()->toMutators(Mutators::named(
                    WhileAlwaysFalse::class,
                    TrueToFalse::class,
                    RemoveArrayItem::class,
                )),
            ),
        );
        $recorded = (string) file_get_contents(Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl', Library::DIRECTORY)));
    } finally {
        unlink($source);
        unlink($test);
    }

    $judged = array_map(static fn(Mutant $mutant): array => [
        $mutant->location()->start()->number(),
        substr((string) strrchr($mutant->mutator(), '\\'), 1),
        $mutant->status(),
        array_map(static fn(TestId $killer): string => $killer->value(), [...$mutant->killers()]),
    ], $result instanceof MutationResult ? [...$result->mutants()] : []);
    usort($judged, static fn(array $one, array $other): int => [$one[0], $one[1]] <=> [$other[0], $other[1]]);
    $killer = ['P\\Tests\\LoopedSpec::__pest_evaluable_it_returns_the_item_twice'];

    expect($judged)->toBe([
        [20, 'TrueToFalse', MutantStatus::Killed, $killer],
        [20, 'WhileAlwaysFalse', MutantStatus::Killed, $killer],
        [21, 'RemoveArrayItem', MutantStatus::Killed, $killer],
        [21, 'RemoveArrayItem', MutantStatus::Killed, $killer],
    ])
        ->and(substr_count($recorded, '"event":"twin"'))->toBe(2)
        ->and(substr_count($recorded, '"event":"planned"'))->toBe(2);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
