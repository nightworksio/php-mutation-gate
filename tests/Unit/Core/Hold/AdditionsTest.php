<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Addition;
use NightWorksIO\MutationGate\Core\Hold\Additions;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** Holds of src/Git by GitTest::runs and of src/Shell.php by ShellTest::starts, from the holding suites. */
function additionsOfGitAndShell(): Additions
{
    return Additions::none()
        ->with(Addition::of(Path::of('src/Git'), TestIds::of(TestId::of('GitTest::runs')), 'holds:src/Git'))
        ->with(Addition::of(Path::of('src/Shell.php'), TestIds::of(TestId::of('ShellTest::starts')), 'holds:src/Shell.php'));
}

it('holds nothing to begin with', function (): void {
    expect(count(Additions::none()))->toBe(0)
        ->and(Additions::none()->paths())->toEqual(Paths::none())
        ->and(Additions::none()->holds(Path::of('src/Git/Command.php')))->toBeFalse()
        ->and(Additions::none()->adds(TestId::of('GitTest::runs'), Path::of('src/Git/Command.php')))->toBeFalse();
});

it('lists each hold, the paths they hold, and whether one holds a file', function (): void {
    $additions = additionsOfGitAndShell();

    expect(count($additions))->toBe(2)
        ->and(array_map(static fn(Addition $addition): string => $addition->written(), [...$additions]))
        ->toBe(['holds:src/Git', 'holds:src/Shell.php'])
        ->and($additions->paths())->toEqual(Paths::of(Path::of('src/Git'), Path::of('src/Shell.php')))
        ->and($additions->holds(Path::of('src/Git/Command.php')))->toBeTrue()
        ->and($additions->holds(Path::of('src/Shell.php')))->toBeTrue()
        ->and($additions->holds(Path::of('src/Money.php')))->toBeFalse();
});

it('adds a test on a line of a file where one of them holds what it runs, and nowhere else', function (): void {
    $additions = additionsOfGitAndShell();

    expect($additions->adds(TestId::of('GitTest::runs'), Path::of('src/Git/Command.php')))->toBeTrue()
        ->and($additions->adds(TestId::of('ShellTest::starts'), Path::of('src/Shell.php')))->toBeTrue()
        ->and($additions->adds(TestId::of('ShellTest::starts'), Path::of('src/Git/Command.php')))->toBeFalse()
        ->and($additions->adds(TestId::of('GitTest::runs'), Path::of('src/Shell.php')))->toBeFalse();
});

it('finds nothing unrun where each hold runs a line of what it holds', function (): void {
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/Git/Command.php'), 4, 'MoneyTest::adds', 'GitTest::runs'),
        CoveredLine::of(Path::of('src/Shell.php'), 9, 'ShellTest::starts'),
    );

    expect(additionsOfGitAndShell()->unrunIn($map))->toEqual(NotGiven::value());
});

it('names the first hold that runs no line of what it holds, though it runs lines elsewhere', function (): void {
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/Git/Command.php'), 4, 'GitTest::runs'),
        CoveredLine::of(Path::of('src/Money.php'), 9, 'ShellTest::starts'),
        CoveredLine::of(Path::of('src/Shell.php'), 2, 'MoneyTest::adds'),
    );

    expect(additionsOfGitAndShell()->unrunIn($map))->toEqual(CannotJudge::because(<<<'SAID'
        holds:src/Shell.php in the holding suites runs no line of src/Shell.php, so it judges none of its mutants.
        Add the test that runs it to the group, or remove the hold.
        SAID));
});
