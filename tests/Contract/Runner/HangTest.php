<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Arithmetic\PostDecrementToPostIncrement;

$holds = [
    'holds:src/Adapter/Pest',
];

/** The seconds a limit or a duration holds, none where it holds none. */
function hangSeconds(Seconds|MemoryCap|Unmeasured $time): float
{
    return $time instanceof Seconds ? $time->seconds() : 0.0;
}

// In src/Hang.php one loop says nothing and the other prints as fast as it
// can on its output and its error output; under their mutants neither
// ends. Each one test takes no time, so each own limit is the floor and
// the start-up a run takes. Under the first bounds nothing stops them
// sooner; under the second their mutator is one timeouts.tighter lists, so
// no test finishing for seven seconds stops them first. The sum beside
// them is killed.
it('stops a mutant whose loop never ends, saying nothing or printing, by its limit, names it, and judges the run\'s other mutant, with patched Pest', function (LimitBounds $bounds, string $reason): void {
    $copied = ['src/Hang.php', 'tests/HangSpec.php'];

    foreach ($copied as $file) {
        copy(Tree::at(sprintf('tests/Contract/Runner/hang/%s', $file)), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
    }

    Patch::applyIn(Library::vendor());
    $library = Library::pestWithin(Patching::on(Library::canary()), $bounds, sprintf('pest patched, hanging %s', $reason));
    $hang = Paths::of(Path::of('src/Hang.php'));
    $started = hrtime(as_number: true);

    try {
        $result = $library->runner()->mutate(MutationRequest::of($hang, WholeSuite::tests())
            ->narrowedTo($hang, Narrowing::none()->toMutators(Mutators::named(PostDecrementToPostIncrement::class, PlusToMinus::class))));
    } finally {
        foreach ($copied as $file) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $file)));
        }
    }

    $took = (hrtime(as_number: true) - $started) / 1e9;
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $hanging = array_values(array_filter($mutants, static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::TimedOut));
    $limits = array_map(static fn(Mutant $mutant): float => hangSeconds($mutant->limit()), $hanging);

    expect(array_map(static fn(Mutant $mutant): array => [
        $mutant->location()->file()->value(),
        $mutant->location()->start()->number(),
        $mutant->mutation()->mutator(),
        $mutant->status(),
        $mutant->reason() instanceof Reason ? $mutant->reason()->text() : '',
    ], $mutants))->toBe([
        ['src/Hang.php', 13, PostDecrementToPostIncrement::class, MutantStatus::TimedOut, $reason],
        ['src/Hang.php', 24, PostDecrementToPostIncrement::class, MutantStatus::TimedOut, $reason],
        ['src/Hang.php', 32, PlusToMinus::class, MutantStatus::Killed, ''],
    ])
        ->and(array_map(
            static fn(Mutant $mutant): bool => hangSeconds($mutant->duration()) <= hangSeconds($mutant->limit()) + 0.5,
            $hanging,
        ))->toBe([true, true])
        ->and($took)->toBeLessThan(array_sum($limits) + 15.0);
})->with([
    'at its own limit' => [
        fn(): LimitBounds => LimitBounds::between(Seconds::of(3.0), Seconds::of(300.0)),
        '',
    ],
    'at its silence limit' => [
        fn(): LimitBounds => LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))
            ->tighterFor(TighterSilence::of(Seconds::of(7.0), 'PostDecrementToPostIncrement')),
        fn(): string => Reason::silent(Seconds::of(7.0))->text(),
    ],
])->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);
