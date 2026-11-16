<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\ProvenMutants;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ProvesBeforeRunFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project with one file and the mutants Pest planned and made of it: a plus and a minus on line 5, a twin of the
 * plus, and a plus on line 6, with the records of them.
 *
 * @return array{Project, Records, list<PlannedMutant>}
 */
function provenProject(): array
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b - 1;\n    return \$a + 2;\n}\n");
    $file = DiskPath::of(sprintf('%s/src/Money.php', $root));
    $planned = static function (string $id, int $line, string $mutator) use ($root, $file): PlannedMutant {
        Scratch::write($root, sprintf('copies/%s', $id), sprintf('<?php // %s', $id));

        return PlannedMutant::of($id, $file, Line::of($line), Line::of($line), $mutator, $id, DiskPath::of(sprintf('%s/copies/%s', $root, $id)));
    };
    $mutants = [
        $planned('plus', 5, PlusToMinus::class),
        $planned('minus', 5, MinusToPlus::class),
        $planned('twin', 5, MinusToPlus::class)->asTwin(),
        $planned('later', 6, PlusToMinus::class),
    ];
    $results = sprintf('%s/results.jsonl', $root);
    file_put_contents($results, implode('', [
        ...array_map(
            static fn(PlannedMutant $mutant): string => RecordLine::planned($mutant, $mutant->isTwin() ? RecordEvent::Twin : RecordEvent::Planned),
            $mutants,
        ),
        RecordLine::made(4, Seconds::of(1.0)),
    ]));
    $records = Records::in($results);

    return [
        Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor')),
        $records instanceof Records ? $records : throw new RuntimeException('The records cannot be read.'),
        $mutants,
    ];
}

$copy = static fn(PlannedMutant $mutant): string => $mutant->mutated()->value();

it('decides a mutant proven equivalent before it runs, with what proved it, and leaves every other to run', function () use ($copy): void {
    [$project, $records, [$plus, $minus, $twin]] = provenProject();
    $proof = Reason::that('Its op arrays are its original\'s.');
    $proven = ProvenMutants::among($records, $project, new ProvesBeforeRunFake([$copy($plus) => $proof, $copy($twin) => $proof]), duplicatesOnce: true);

    expect($proven->equivalenceOf($plus))->toBe($proof)
        ->and($proven->equivalenceOf($minus))->toEqual(NotGiven::value())
        ->and([$proven->decides($plus), $proven->decides($minus), $proven->decides($twin)])->toBe([true, false, false])
        ->and($proven->copies())->toBe([$copy($plus)])
        ->and($proven->judgedAs($minus))->toBe($minus);
});

it('judges once a mutant whose program and declarations are those of one made before it at its lines, as that one\'s twin', function () use ($copy): void {
    [$project, $records, [$plus, $minus, , $later]] = provenProject();
    $programs = [$copy($plus) => 'same', $copy($minus) => 'same', $copy($later) => 'same'];
    $declaring = ['<?php // plus' => 'add', '<?php // minus' => 'add', '<?php // later' => 'add'];
    $proven = ProvenMutants::among($records, $project, new ProvesBeforeRunFake([], $programs, $declaring), duplicatesOnce: true);
    $apart = ProvenMutants::among($records, $project, new ProvesBeforeRunFake([], $programs, ['<?php // plus' => 'add', '<?php // minus' => 'sub']), duplicatesOnce: true);
    $everyOne = ProvenMutants::among($records, $project, new ProvesBeforeRunFake([], $programs, $declaring), duplicatesOnce: false);

    expect($proven->copies())->toBe([$copy($minus)])
        ->and($proven->judgedAs($minus))->toEqual($minus->asTwin()->servedAs(DiskPath::of($copy($plus))))
        ->and([$proven->decides($plus), $proven->decides($later)])->toBe([false, false])
        ->and($apart->copies())->toBe([])
        ->and($everyOne->copies())->toBe([])
        ->and(ProvenMutants::none()->copies())->toBe([]);
});
