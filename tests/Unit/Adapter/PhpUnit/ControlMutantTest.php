<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\ControlMutant;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project holding `src/Money.php`. */
function controlMutantProject(): Project
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nreturn 1 + 1;\n");

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
}

/** The mutant of a control of `src/Money.php` by these tests, allowed this long, failing where it cannot be made. */
function controlMutantOf(Project $project, float $limit, string ...$tests): MadeMutant
{
    $made = ControlMutant::of($project, Control::of(
        Path::of('src/Money.php'),
        TestIds::of(...array_map(TestId::of(...), $tests)),
        Seconds::of($limit),
    ));

    return $made instanceof MadeMutant ? $made : throw new LogicException($made->why());
}

it('makes a mutant that changes nothing: the file as the project holds it, served in the original\'s place', function (): void {
    $made = controlMutantOf(controlMutantProject(), 5.0, 'MoneyTest::adds');

    expect($made->mutated()->text())->toBe("<?php\n\nreturn 1 + 1;\n")
        ->and($made->location()->file())->toEqual(Path::of('src/Money.php'))
        ->and($made->mutation()->diff())->toBe('');
});

it('gives each control of a file an id of its own, and the same control the same id', function (): void {
    $project = controlMutantProject();
    $ids = array_map(static fn(MadeMutant $made): string => $made->id()->value(), [
        controlMutantOf($project, 5.0, 'MoneyTest::adds'),
        controlMutantOf($project, 5.0, 'MoneyTest::adds'),
        controlMutantOf($project, 6.0, 'MoneyTest::adds'),
        controlMutantOf($project, 5.0, 'MoneyTest::subtracts'),
    ]);

    expect($ids[0])->toBe($ids[1])
        ->and(array_unique([$ids[0], $ids[2], $ids[3]]))->toHaveCount(3);
});

it('cannot make the mutant of a file it cannot read', function (): void {
    $made = ControlMutant::of(controlMutantProject(), Control::of(Path::of('src/Gone.php'), TestIds::none(), Seconds::of(5.0)));

    expect($made)->toEqual(CannotJudge::because(sprintf(Control::UNREAD, 'src/Gone.php')));
});
