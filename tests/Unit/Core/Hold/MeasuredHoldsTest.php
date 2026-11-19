<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Plan\MeasuredHoldsRecord;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/** A unit at this path, held by its own group. */
function heldUnit(string $path): Unit
{
    return Unit::held(Path::of($path), Group::named(sprintf('holds:%s', $path)));
}

it('measures a shard\'s units only where it holds every held one of them, whatever it holds besides', function (): void {
    $measured = MeasuredHolds::none()->with(heldUnit('src/A.php'), Covered::by(heldUnit('src/A.php'), TestIds::none()));

    expect($measured->measures(Units::of(heldUnit('src/A.php'), Unit::file(Path::of('src/B.php')))))->toBeTrue()
        ->and($measured->measures(Units::of(heldUnit('src/A.php'), heldUnit('src/C.php'))))->toBeFalse()
        ->and($measured->measures(Units::none()))->toBeTrue();
});

it('gives each held unit of a shard the check the plan made of it, with the shard\'s own unit', function (): void {
    $a = heldUnit('src/A.php');
    $b = heldUnit('src/B.php');
    $measured = MeasuredHolds::none()
        ->with($a, Covered::by($a, TestIds::of(TestId::of('ATest::runs'))))
        ->with($b, NotCovered::because($b, 'misses line 3'));
    $checks = $measured->checksOf(Units::of($a, $b, Unit::file(Path::of('src/C.php'))));

    expect($checks)->toBeInstanceOf(HeldChecks::class)
        ->and($checks instanceof HeldChecks ? $checks->covered()->testsOf(Path::of('src/A.php')) : null)
        ->toEqual(TestIds::of(TestId::of('ATest::runs')))
        ->and($checks instanceof HeldChecks ? [...$checks->misses()] : [])->toEqual([NotCovered::because($b, 'misses line 3')])
        ->and($checks instanceof HeldChecks ? $checks->covered()->count() : 0)->toBe(1);
});

it('cannot judge a shard holding a unit whose tests the plan could not run on their own', function (): void {
    $a = heldUnit('src/A.php');
    $measured = MeasuredHolds::none()
        ->with($a, CannotJudge::because('they fail alone'))
        ->with(heldUnit('src/B.php'), Covered::by(heldUnit('src/B.php'), TestIds::none()));

    expect($measured->checksOf(Units::of(heldUnit('src/B.php'), $a)))->toEqual(CannotJudge::because('they fail alone'))
        ->and($measured->checksOf(Units::of(heldUnit('src/B.php'))))->toBeInstanceOf(HeldChecks::class);
});

it('is written to the plan file and read back as it was', function (): void {
    $a = heldUnit('src/A.php');
    $measured = MeasuredHolds::none()
        ->with($a, Covered::by($a, TestIds::of(TestId::of('ATest::runs'), TestId::of('ATest::stops'))))
        ->with(heldUnit('src/B.php'), NotCovered::because(heldUnit('src/B.php'), 'misses line 3'))
        ->with(heldUnit('src/C.php'), CannotJudge::because('they fail alone'));
    $written = MeasuredHoldsRecord::of($measured);
    $read = MeasuredHoldsRecord::read(Node::decode((string) json_encode($written)));

    expect($written)->toBe([
        'src/A.php' => ['tests' => ['ATest::runs', 'ATest::stops']],
        'src/B.php' => ['misses' => 'misses line 3'],
        'src/C.php' => ['failed' => 'they fail alone'],
    ])
        ->and($read->checksOf(Units::of($a)))->toEqual($measured->checksOf(Units::of($a)))
        ->and($read->checksOf(Units::of(heldUnit('src/B.php'))))->toEqual($measured->checksOf(Units::of(heldUnit('src/B.php'))))
        ->and($read->checksOf(Units::of(heldUnit('src/C.php'))))->toEqual(CannotJudge::because('they fail alone'));
});
