<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\PreCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\ControlStructures\WhileAlwaysFalse;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use Pest\Mutate\Mutators\Removal\RemoveArrayItem;

// Patched Pest hands every mutant it planned to the gate's static analysis
// before the first one runs (ADR-0020, decision 12), and runs none the gate
// rejects. Looped.php's `while (false)` pair, rejected here, never runs, and
// neither twin starts a process; the pair of `['x']` runs as ever.

it('runs no mutant the gate\'s static analysis rejects before its tests, nor its twin, and kills each by static analysis', function (): void {
    $source = Tree::at(sprintf('%s/src/Looped.php', Library::DIRECTORY));
    $test = Tree::at(sprintf('%s/tests/LoopedSpec.php', Library::DIRECTORY));
    copy(Tree::at('tests/Contract/Runner/twins/src/Looped.php'), $source);
    copy(Tree::at('tests/Contract/Runner/twins/tests/LoopedSpec.php'), $test);
    Patch::applyIn(Library::vendor());
    $checker = new PreCheckerFake([WhileAlwaysFalse::class, TrueToFalse::class]);

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
            $checker,
        );
        $recorded = (string) file_get_contents(Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl', Library::DIRECTORY)));
    } finally {
        unlink($source);
        unlink($test);
    }

    $judged = array_map(static fn(Mutant $mutant): array => [
        substr((string) strrchr($mutant->mutator(), '\\'), 1),
        $mutant->status()->value,
    ], $result instanceof MutationResult ? [...$result->mutants()] : []);
    sort($judged);

    expect($judged)->toBe([
        ['RemoveArrayItem', 'killed'],
        ['RemoveArrayItem', 'killed'],
        ['TrueToFalse', 'killed-by-static-analysis'],
        ['WhileAlwaysFalse', 'killed-by-static-analysis'],
    ])
        ->and(array_map(static fn(string $offered): string => substr((string) strrchr(explode(' ', $offered)[0], '\\'), 1), $checker->offered()))
        ->toBe(['WhileAlwaysFalse', 'RemoveArrayItem'])
        ->and(substr_count($recorded, '"event":"arguments"'))->toBe(1);
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');
