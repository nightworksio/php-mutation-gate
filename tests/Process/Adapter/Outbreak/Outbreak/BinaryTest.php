<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\VouchedMaps;

// Outbreak's core as this commit builds it, where OUTBREAK_BINARY names it, as
// the `outbreak / boundary` job does (ADR-0118): it speaks contract 1, vouches
// for every map the gate writes, and slices one.

$holds = ['holds:src/Adapter/Outbreak/Outbreak.php'];

$binary = static fn(): string => (string) getenv('OUTBREAK_BINARY');

it('vouches for every map the careful reading reads whole', function () use ($binary): void {
    $engine = Outbreak::at($binary(), Scratch::directory());

    expect($engine->isInUse())->toBeTrue();

    foreach (VouchedMaps::texts() as $name => [$json]) {
        expect($engine->vouches(Contents::of($json)))->toBe(MapVouch::Vouched, $name);
    }
})->skip(fn(): bool => ! is_string(getenv('OUTBREAK_BINARY')), 'OUTBREAK_BINARY names no binary; the outbreak / boundary job builds one')
    ->group(...$holds);

it('slices a whole map the gate wrote', function () use ($binary): void {
    $root = Scratch::directory();
    $json = VouchedMaps::texts()['two paths that read as one'][0];
    $map = CoverageMapFile::decode(Gzip::pack($json), HandedMaps::limits());
    $text = MapText::of($map instanceof CoverageMap ? $map : CoverageMap::of());
    Scratch::write($root, 'map.json.gz', CoverageMapFile::encode($text, Unplaced::map()));

    expect(Outbreak::at($binary(), $root)->split(
        Path::of('map.json.gz'),
        MapSlices::of(MapSlice::of(Path::of('shard-1/map.json.gz'), Paths::of(Path::of('src/A.php')))),
    ))->toBeInstanceOf(Written::class);
})->skip(fn(): bool => ! is_string(getenv('OUTBREAK_BINARY')), 'OUTBREAK_BINARY names no binary; the outbreak / boundary job builds one')
    ->group(...$holds);
