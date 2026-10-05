<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Support\Gunzipped;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;

$commit = '0123456789abcdef0123456789abcdef01234567';

it('records where a whole map was measured beside its format, and reads it back, the map unchanged', function () use (
    $commit,
): void {
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));
    $at = MeasuredAt::of(Revision::ref($commit), dirty: true);
    $bytes = CoverageMapFile::encode($map, $at);

    expect(Gunzipped::of($bytes))->toStartWith(sprintf('{"format":1,"commit":"%s","dirty":true,"tests":', $commit))
        ->and(MeasuredAt::recordedIn($bytes, HandedMaps::limits()))->toEqual($at)
        ->and(CoverageMapFile::decode($bytes, HandedMaps::limits()))->toEqual($map);
});

it('records nothing for a map that does not say where it was measured', function (): void {
    $bytes = CoverageMapFile::encode(CoverageMap::empty(), Unplaced::map());

    expect(Gunzipped::of($bytes))->toBe('{"format":1,"tests":[],"files":{}}')
        ->and(MeasuredAt::recordedIn($bytes, HandedMaps::limits()))->toEqual(Unplaced::map());
});

it('reads a map as unplaced where its commit or its dirtiness is missing or not one', function (array $fields): void {
    expect(MeasuredAt::recordedIn(Gzip::pack(JsonText::compact(['format' => 1, ...$fields])), HandedMaps::limits()))->toEqual(Unplaced::map());
})->with([
    'no commit' => [['dirty' => false]],
    'no dirtiness' => [['commit' => '0123456789abcdef0123456789abcdef01234567']],
    'not a commit' => [['commit' => 'main', 'dirty' => false]],
    'dirtiness not a boolean' => [['commit' => '0123456789abcdef0123456789abcdef01234567', 'dirty' => 'no']],
]);

it('reads bytes that are no map as unplaced', function (): void {
    expect(MeasuredAt::recordedIn('not gzip', HandedMaps::limits()))->toEqual(Unplaced::map());
});

it('is measured now at the checkout\'s commit, dirty where the tree is not clean, and unplaced where git cannot tell', function () use (
    $commit,
): void {
    $head = Revision::ref($commit);
    $unknown = CannotTell::because('git is not installed.');

    expect([
        MeasuredAt::now($head, clean: true),
        MeasuredAt::now($head, clean: false),
        MeasuredAt::now($unknown, clean: true),
        MeasuredAt::now($head, $unknown),
    ])->toEqual([
        MeasuredAt::of($head, dirty: false),
        MeasuredAt::of($head, dirty: true),
        Unplaced::map(),
        Unplaced::map(),
    ])->and([MeasuredAt::of($head, dirty: true)->commit(), MeasuredAt::of($head, dirty: true)->isDirty()])
        ->toEqual([$head, true]);
});
