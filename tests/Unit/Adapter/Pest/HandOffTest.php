<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridges;
use NightWorksIO\MutationGate\Adapter\Pest\Covering;
use NightWorksIO\MutationGate\Adapter\Pest\HandedOver;
use NightWorksIO\MutationGate\Adapter\Pest\HandOff;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\PreCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project with one file, whose line 5 one test covers, and the mutants Pest planned of it: a plus on line 5, a
 * twin of it, a minus on line 5, and a plus on line 6 no test covers.
 *
 * @return array{Project, string, list<PlannedMutant>}
 */
function handedProject(): array
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b - 1;\n    return \$a + 2;\n}\n");
    $copy = static function (string $name) use ($root): DiskPath {
        Scratch::write($root, sprintf('copies/%s', $name), sprintf('<?php // %s', $name));

        return DiskPath::of(sprintf('%s/copies/%s', $root, $name));
    };
    $file = DiskPath::of(sprintf('%s/src/Money.php', $root));
    $planned = static fn(string $id, int $line, string $mutator, DiskPath $copy): PlannedMutant => PlannedMutant::of(
        $id,
        $file,
        Line::of($line),
        Line::of($line),
        $mutator,
        sprintf("\n  <fg=red>-        line %d</>\n  <fg=green>+        %s</>\n", $line, $id),
        $copy,
    );
    $plus = $copy('plus');

    return [
        Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor')),
        sprintf('%s/results.jsonl', $root),
        [
            $planned('plus', 5, PlusToMinus::class, $plus),
            $planned('twin', 5, MinusToPlus::class, $plus)->asTwin(),
            $planned('minus', 5, MinusToPlus::class, $copy('minus')),
            $planned('uncovered', 6, PlusToMinus::class, $copy('uncovered')),
        ],
    ];
}

/** The hand-off of a run's results in a project, to a pre-checker, its tests covering line 5 alone. */
function handOff(Project $project, string $results, PreCheckerFake $checker, bool $covered = true): HandOff
{
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('MoneyTest::adds'));

    return new HandOff(
        $project,
        $results,
        $checker,
        ProcessCount::of(2),
        static fn(): Covering|CannotJudge => $covered ? new HandedOver($map, $project) : CannotJudge::because('No map.'),
        new Bridges(),
    );
}

/** @param list<PlannedMutant> $planned */
function handedRecords(string $results, array $planned, bool $made): void
{
    $lines = array_map(
        static fn(PlannedMutant $mutant): string => RecordLine::planned($mutant, $mutant->isTwin() ? RecordEvent::Twin : RecordEvent::Planned),
        $planned,
    );
    file_put_contents($results, implode('', [...$lines, ...$made ? [RecordLine::made(3, Seconds::of(1.0))] : []]));
}

it('waits until Pest has written every mutant it planned, then writes the copy of each the pre-checker rejects', function (): void {
    [$project, $results, $planned] = handedProject();
    $checker = new PreCheckerFake([PlusToMinus::class]);
    $handOff = handOff($project, $results, $checker);

    $handOff->look();
    $unwritten = is_file(Verdicts::beside($results));
    handedRecords($results, $planned, made: false);
    $handOff->look();
    $unmade = is_file(Verdicts::beside($results));
    handedRecords($results, $planned, made: true);
    $handOff->look();
    $handOff->look();

    expect([$unwritten, $unmade])->toBe([false, false])
        ->and(file_get_contents(Verdicts::beside($results)))->toBe($planned[0]->mutated()->value())
        ->and(array_map(static fn(string $offered): string => explode(' ', $offered, 2)[1], $checker->offered()))
        ->toBe(['<?php // plus', '<?php // minus'])
        ->and($checker->sides())->toBe([2])
        ->and($handOff->rejectionOf($planned[0]))->toEqual(PreCheckerFake::rejection(Path::of('src/Money.php')))
        ->and($handOff->rejectionOf($planned[1]))->toBeInstanceOf(Rejection::class)
        ->and($handOff->rejectionOf($planned[2]))->toEqual(NotGiven::value())
        ->and($handOff->rejectionOf($planned[3]))->toEqual(NotGiven::value());
});

it('writes no rejection, so Pest runs every mutant, where the run\'s covering tests cannot be read', function (): void {
    [$project, $results, $planned] = handedProject();
    $checker = new PreCheckerFake([PlusToMinus::class]);
    handedRecords($results, $planned, made: true);

    handOff($project, $results, $checker, covered: false)->look();

    expect(file_get_contents(Verdicts::beside($results)))->toBe('')
        ->and($checker->offered())->toBe([]);
});
