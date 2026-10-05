<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\HandedOver;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Adapter\Pest\Unshared;
use NightWorksIO\MutationGate\Adapter\Pest\WholeMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$own = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));
$whole = $own->covered(Path::of('src/Ledger.php'), Line::of(9), TestId::of('LedgerTest::sums'));
$handed = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
    ->reusingCoverage(Handed::maps(Path::of('own'), Path::of('whole')));
$project = static fn(string $root): Project => Project::at(
    $root,
    Paths::of(Path::of('tests')),
    Path::of('.mutation-gate'),
    Path::of('vendor'),
);

it('finds the tests that read a value in the plan\'s whole map, where the run opened on its shard\'s own', function () use (
    $own,
    $whole,
    $handed,
    $project,
): void {
    $root = Scratch::directory();
    Scratch::write($root, 'whole/map.json.gz', CoverageMapFile::encode($whole, Unplaced::map()));
    $covering = new WholeMap($project($root), new Remembered())
        ->covering($handed, new HandedOver($own, $project($root)), $own);

    expect($covering)->toBeInstanceOf(HandedOver::class)
        ->and($covering instanceof HandedOver ? $covering->map($project($root)) : $covering)
        ->toEqual(CoverageMapFile::decode(CoverageMapFile::encode($whole, Unplaced::map()), HandedMaps::limits()));
});

it('reads the whole map once for each directory', function () use ($own, $whole, $handed, $project): void {
    $root = Scratch::directory();
    Scratch::write($root, 'whole/map.json.gz', CoverageMapFile::encode($whole, Unplaced::map()));
    $wholeMap = new WholeMap($project($root), new Remembered());
    $first = $wholeMap->covering($handed, new HandedOver($own, $project($root)), $own);
    unlink(sprintf('%s/whole/map.json.gz', $root));
    $again = $wholeMap->covering($handed, new HandedOver($own, $project($root)), $own);

    expect($again)->toEqual($first);
});

it('keeps the run\'s own map where the run opened on its own suite, or was handed no maps', function () use (
    $own,
    $handed,
    $project,
): void {
    $ownRun = new HandedOver($own, $project('/p'));
    $wholeMap = new WholeMap($project('/p'), new Remembered());
    $fresh = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    expect($wholeMap->covering($handed, $ownRun, Unshared::Coverage))->toBe($ownRun)
        ->and($wholeMap->covering($fresh, $ownRun, $own))->toBe($ownRun);
});

it('cannot find the tests that read a value where the shard was handed no whole map', function () use (
    $own,
    $handed,
    $project,
): void {
    $at = $project(Scratch::directory());

    expect(new WholeMap($at, new Remembered())->covering($handed, new HandedOver($own, $at), $own))
        ->toEqual(CoverageMapFile::missingAt($at->absolute(CoverageMapFile::in(Path::of('whole')))));
});
