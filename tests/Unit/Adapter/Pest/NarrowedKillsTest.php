<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\NarrowedKills;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** The mutant of src/Money.php's line with this number, whose native id is `native-<line>`, with this status and killers. */
$mutant = static function (int $line, MutantStatus $status, string ...$killers): Mutant {
    $made = Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line));

    return Mutant::of($made->id(), $made->nativeId(), $made->location(), $made->mutation(), $status, Unmeasured::duration())
        ->killedBy(TestIds::of(...array_map(TestId::of(...), $killers)));
};

/** A results file in which Pest planned the mutants of lines 1 to 7, and then wrote these lines. */
function narrowedResults(string ...$lines): string
{
    $file = sprintf('%s/results.jsonl', Scratch::directory());
    $planned = array_map(
        static fn(int $line): string => PestRun::planned(sprintf('native-%d', $line), '/p/src/Money.php', $line, 'Plus', 'a', 'b'),
        range(1, 7),
    );
    PestRun::write($file, array_values([...$planned, ...$lines]));

    return $file;
}

it('doubts a kill no test is named for, one only errors made, one with no record, and a narrowed one its files do not vouch for', function () use ($mutant): void {
    $mutants = [
        $mutant(1, MutantStatus::Killed, 'T::a'),
        $mutant(2, MutantStatus::Killed, 'T::b'),
        $mutant(3, MutantStatus::Killed, 'T::c'),
        $mutant(4, MutantStatus::Killed, 'T::d'),
        $mutant(5, MutantStatus::Killed, 'T::e'),
        $mutant(6, MutantStatus::Killed),
        $mutant(7, MutantStatus::Survived),
        $mutant(8, MutantStatus::Killed, 'T::h'),
    ];
    $file = narrowedResults(
        PestRun::killed('native-1', 'T::a'),
        PestRun::narrowed('native-1', ['/p/tests/ASpec.php', '/p/tests/BSpec.php']),
        PestRun::killed('native-2', 'T::b'),
        PestRun::narrowed('native-2', ['/p/tests/ASpec.php', '/p/tests/BSpec.php']),
        PestRun::killed('native-3', 'T::c'),
        PestRun::narrowed('native-3', ['/p/tests/CSpec.php']),
        PestRun::killed('native-4', 'T::d'),
        PestRun::errored('native-5', 'T::e'),
        PestRun::narrowed('native-7', ['/p/tests/CSpec.php']),
        PestRun::finished('native-1', PestStatus::Tested, 0.1),
    );
    $asked = [];

    $doubted = NarrowedKills::in(MutationResult::of(Mutants::of(...$mutants), 0), $file)->doubted(
        static function (array $files) use (&$asked): bool {
            $asked[] = $files;

            return $files !== ['/p/tests/CSpec.php'];
        },
    );

    expect(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))
        ->toEqualCanonicalizing(['native-3', 'native-5', 'native-6', 'native-8'])
        ->and($asked)->toBe([['/p/tests/ASpec.php', '/p/tests/BSpec.php'], ['/p/tests/CSpec.php']]);
});

it('never doubts a kill of a mutant Pest left uncovered, which the trial judged', function () use ($mutant): void {
    $file = narrowedResults(
        PestRun::finished('native-1', PestStatus::Uncovered, 0.0),
        PestRun::finished('native-2', PestStatus::Tested, 0.1),
    );

    $doubted = NarrowedKills::in(
        MutationResult::of(Mutants::of($mutant(1, MutantStatus::Killed), $mutant(2, MutantStatus::Killed)), 0),
        $file,
    )->doubted(static fn(): bool => true);

    expect(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))->toBe(['native-2']);
});

it('doubts every kill, and asks nothing, where the records cannot be read', function () use ($mutant): void {
    $killed = $mutant(1, MutantStatus::Killed, 'T::a');
    $asked = 0;

    $doubted = NarrowedKills::in(
        MutationResult::of(Mutants::of($killed, $mutant(2, MutantStatus::Survived)), 0),
        sprintf('%s/missing.jsonl', Scratch::directory()),
    )->doubted(static function () use (&$asked): bool {
        $asked++;

        return true;
    });

    expect([...$doubted])->toEqual([$killed])
        ->and($asked)->toBe(0);
});
