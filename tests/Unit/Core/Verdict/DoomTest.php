<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$mutant = static fn(string $file, int $line, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'LessThan', sprintf('%d', $line), 0),
    sprintf('%s:%d', $file, $line),
    Location::of(Path::of($file), Line::of($line), Line::of($line)),
    Mutation::of('LessThan', MutatorFamily::Boundary, ''),
    $status,
    Unmeasured::duration(),
);
$root = Package::at(Path::root());
$whole = Tree::at(Path::of('src'), Floor::whole(), $root);
$half = Tree::at(Path::of('lib'), Floor::of(50), $root);
$raised = Tree::at(Path::of('app'), Floor::of(50), $root);
$exempt = Tree::at(Path::of('legacy'), Exempt::because('Not judged yet'), $root);
$own = Tree::at(Path::of('billing'), Floor::of(50), $root)->withNewCodeFloor(Floor::of(80));
$trees = Trees::of($whole, $half, $raised, $exempt, $own);
$reach = Reach::nothing(Packages::of($trees))
    ->withLines(Path::of('lib/Changed.php'), Lines::of(Line::of(3)))
    ->withLines(Path::of('src/Money.php'), Lines::of(Line::of(3)))
    ->withLines(Path::of('legacy/Old.php'), Lines::of(Line::of(3)))
    ->withLines(Path::of('billing/Invoice.php'), Lines::of(Line::of(3)));
$doom = static fn(Floor $newCode): Doom => Doom::over(
    $trees,
    Baseline::of(Entry::of(Path::of('app'), Floor::whole())),
    $reach,
    $newCode,
    Ignoring::none(),
);
$units = static fn(string ...$paths): Units => Units::of(...array_map(static fn(string $path): Unit => Unit::file(Path::of($path)), $paths));

/** @return array{string, string, string, int, string}|string what a doom names, or that there is none */
$named = static fn(Doomed|Undoomed $doomed): array|string => $doomed instanceof Doomed
    ? [$doomed->unit()->value(), $doomed->mutant()->value(), $doomed->tree()->value(), $doomed->floor()->hundredths(), $doomed->by()->value]
    : 'undoomed';

it('dooms a survivor in a tree whose floor is 100, naming its unit, its mutant, the tree and the floor', function () use ($doom, $mutant, $units, $named): void {
    $survivor = $mutant('src/Money.php', 7, MutantStatus::Survived);
    $mutants = Mutants::of($mutant('src/Money.php', 6, MutantStatus::Killed), $survivor);

    expect($named($doom(Floor::of(80))->first($units('src/Money.php'), $mutants, MutantIds::none())))
        ->toBe(['src/Money.php', $survivor->id()->value(), 'src', 10_000, 'tree']);
});

it('dooms a survivor in a tree the baseline holds to 100 above the floor it declares', function () use ($doom, $mutant, $units, $named): void {
    $survivor = $mutant('app/Kernel.php', 2, MutantStatus::Survived);

    expect($named($doom(Floor::of(80))->first($units('app/Kernel.php'), Mutants::of($survivor), MutantIds::none())))
        ->toBe(['app/Kernel.php', $survivor->id()->value(), 'app', 10_000, 'tree']);
});

it('dooms a survivor on a changed line where the new-code floor is 100, below a tree floor that is not', function () use ($doom, $mutant, $units, $named): void {
    $changed = $mutant('lib/Changed.php', 3, MutantStatus::Survived);
    $unchanged = $mutant('lib/Changed.php', 4, MutantStatus::Survived);

    expect($named($doom(Floor::whole())->first($units('lib/Changed.php'), Mutants::of($unchanged, $changed), MutantIds::none())))
        ->toBe(['lib/Changed.php', $changed->id()->value(), 'lib', 10_000, 'newCode'])
        ->and($named($doom(Floor::of(99.99))->first($units('lib/Changed.php'), Mutants::of($changed), MutantIds::none())))
        ->toBe('undoomed')
        ->and($named($doom(Floor::whole())->first($units('lib/Changed.php'), Mutants::of($unchanged), MutantIds::none())))
        ->toBe('undoomed');
});

it('holds a changed line to the new-code floor its tree declares in place of the run\'s', function () use ($doom, $mutant, $units, $named): void {
    $changed = $mutant('billing/Invoice.php', 3, MutantStatus::Survived);

    expect($named($doom(Floor::whole())->first($units('billing/Invoice.php'), Mutants::of($changed), MutantIds::none())))
        ->toBe('undoomed');
});

it('judges a changed line of an exempt tree by the new-code floor, as the verdict does, and its other lines by none', function () use ($doom, $mutant, $units, $named): void {
    $changed = $mutant('legacy/Old.php', 3, MutantStatus::Survived);
    $unchanged = $mutant('legacy/Old.php', 4, MutantStatus::Survived);

    expect($named($doom(Floor::whole())->first($units('legacy/Old.php'), Mutants::of($unchanged, $changed), MutantIds::none())))
        ->toBe(['legacy/Old.php', $changed->id()->value(), 'legacy', 10_000, 'newCode']);
});

it('names the tree\'s floor before the new code\'s where a survivor fails both', function () use ($doom, $mutant, $units, $named): void {
    $survivor = $mutant('src/Money.php', 3, MutantStatus::Survived);

    expect($named($doom(Floor::whole())->first($units('src/Money.php'), Mutants::of($survivor), MutantIds::none())))
        ->toBe(['src/Money.php', $survivor->id()->value(), 'src', 10_000, 'tree']);
});

it('dooms nothing but a survivor: not a kill, a timeout, an uncovered, errored or unjudged mutant, nor one out of memory or a marker ignores', function (MutantStatus $status) use ($doom, $mutant, $units, $named): void {
    expect($named($doom(Floor::whole())->first($units('src/Money.php'), Mutants::of($mutant('src/Money.php', 3, $status)), MutantIds::none())))
        ->toBe('undoomed');
})->with([
    'killed' => [MutantStatus::Killed],
    'timed out' => [MutantStatus::TimedOut],
    'uncovered' => [MutantStatus::Uncovered],
    'errored' => [MutantStatus::Errored],
    'unjudged' => [MutantStatus::Unjudged],
    'out of memory' => [MutantStatus::OutOfMemory],
    'ignored by marker' => [MutantStatus::IgnoredByMarker],
]);

it('dooms no survivor that proved flaky, or that an ignore names', function () use ($trees, $reach, $mutant, $units, $named): void {
    $survivor = $mutant('src/Money.php', 7, MutantStatus::Survived);
    $ignoring = Ignoring::of(
        Listed::of(IgnoredMutant::of($survivor->id(), 'Both branches build the same list', Absent::setting())),
        new DateTimeImmutable(Configs::NOW),
    );
    $over = static fn(Ignoring $ignoring): Doom => Doom::over($trees, Baseline::none(), $reach, Floor::whole(), $ignoring);

    expect($named($over(Ignoring::none())->first($units('src/Money.php'), Mutants::of($survivor), MutantIds::of($survivor->id()))))
        ->toBe('undoomed')
        ->and($named($over($ignoring)->first($units('src/Money.php'), Mutants::of($survivor), MutantIds::none())))
        ->toBe('undoomed')
        ->and($named($over(Ignoring::none())->first($units('src/Money.php'), Mutants::of($survivor), MutantIds::none())))
        ->toBe(['src/Money.php', $survivor->id()->value(), 'src', 10_000, 'tree']);
});

it('dooms no survivor of a tree below 100 off the changed lines, nor one outside every tree', function () use ($doom, $mutant, $units, $named): void {
    $mutants = Mutants::of($mutant('lib/Loose.php', 4, MutantStatus::Survived), $mutant('vendor/Other.php', 3, MutantStatus::Survived));

    expect($named($doom(Floor::whole())->first($units('lib/Loose.php', 'vendor/Other.php'), $mutants, MutantIds::none())))
        ->toBe('undoomed');
});

it('names a held unit by its path, of a survivor in a file inside it, and the first doomed survivor in order', function () use ($doom, $mutant, $named): void {
    $first = $mutant('src/Held/Inner.php', 4, MutantStatus::Survived);
    $later = $mutant('src/Money.php', 7, MutantStatus::Survived);
    $units = Units::of(Unit::file(Path::of('src/Money.php')), Unit::held(Path::of('src/Held'), Group::named('holds:src/Held')));

    expect($named($doom(Floor::whole())->first($units, Mutants::of($first, $later), MutantIds::none())))
        ->toBe(['src/Held', $first->id()->value(), 'src', 10_000, 'tree']);
});

it('dooms no survivor of a file outside the units it is given', function () use ($doom, $mutant, $units, $named): void {
    expect($named($doom(Floor::whole())->first($units('src/Other.php'), Mutants::of($mutant('src/Money.php', 7, MutantStatus::Survived)), MutantIds::none())))
        ->toBe('undoomed');
});

it('spells why a survivor dooms a run as the shard result records it', function (): void {
    expect(DoomedBy::Tree->value)->toBe('tree')
        ->and(DoomedBy::NewCode->value)->toBe('newCode')
        ->and(DoomedBy::tryFrom('newcode'))->toBeNull();
});

it('says which shard stopped, on which survivor, and the floor it fails', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', '7', 0);
    $byTree = Doomed::of(Path::of('src/Money.php'), $id, Path::of('src'), Floor::whole(), DoomedBy::Tree);
    $byNewCode = Doomed::of(Path::of('lib/Changed.php'), $id, Path::of('lib'), Floor::whole(), DoomedBy::NewCode);

    expect($byTree->said(3))->toBe(sprintf(
        "Shard 3 stopped once this run could not pass: mutant %s of src/Money.php survived, and the floor of tree src is 100%%.\n"
        . 'The units it did not run are unjudged. Once that mutant is killed, a run judges them.',
        $id->value(),
    ))
        ->and($byNewCode->said(1))->toBe(sprintf(
            "Shard 1 stopped once this run could not pass: mutant %s of lib/Changed.php survived, and it is on a line the change added or modified, whose new-code floor is 100%%.\n"
            . 'The units it did not run are unjudged. Once that mutant is killed, a run judges them.',
            $id->value(),
        ));
});
