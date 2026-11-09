<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Adapter\Outbreak\ReportAnswer;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\CoverageReports;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGate\Tests\Support\VouchedMaps;

// Outbreak's core as this commit builds it, where OUTBREAK_BINARY names it, as
// the `outbreak / boundary` job does (ADR-0118, ADR-0119): it speaks contract
// 1, vouches for every map the gate writes, slices one, reads every report of
// the corpus, and declines each report php-code-coverage does not write.

$holds = ['holds:src/Adapter/Outbreak/Outbreak.php', 'holds:src/Adapter/Outbreak/ReportAnswer.php'];

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

it('outlines and converts every report of the corpus', function () use ($binary): void {
    foreach (CoverageReports::all() as $name => $directory) {
        $root = Scratch::directory();
        $engine = Outbreak::at($binary(), $root);
        $report = CoverageReports::ran($directory);
        $outline = $engine->outlining($report)->outline();

        expect($outline)->toBeInstanceOf(MapOutline::class, $name);

        $conversion = CoverageReports::conversion($directory, $outline instanceof MapOutline ? $outline : MapOutline::of(CoverageMap::of()), admitted: false);

        expect($engine->convert($report, $conversion, Path::of('map.json.gz')))->toBeInstanceOf(Written::class, $name);
    }
})->skip(fn(): bool => ! is_string(getenv('OUTBREAK_BINARY')), 'OUTBREAK_BINARY names no binary; the outbreak / boundary job builds one')
    ->group(...$holds);

it('declines each report of the corpus outside what php-code-coverage writes, saying why', function () use ($binary): void {
    $engine = Outbreak::at($binary(), Scratch::directory());
    /** @var array<string, string> $declined */
    $declined = json_decode((string) file_get_contents(Tree::at('tests/Fixtures/DeclinedReports/declined.json')), associative: true);

    foreach ($declined as $name => $why) {
        $report = RanReport::at(Tree::at(sprintf('tests/Fixtures/DeclinedReports/%s.report', $name)), 4, RecordedBeside::nothing(), Path::of(...));

        expect($engine->outlining($report)->outline())->toEqual(NotWritten::because(sprintf(ReportAnswer::DECLINED, $why)), $name);
    }

    $reports = glob(Tree::at('tests/Fixtures/DeclinedReports/*.report'));

    expect($declined)->toHaveCount(count($reports === false ? [] : $reports));
})->skip(fn(): bool => ! is_string(getenv('OUTBREAK_BINARY')), 'OUTBREAK_BINARY names no binary; the outbreak / boundary job builds one')
    ->group(...$holds);

it('declines a report in a format other than the one the runner reads', function () use ($binary): void {
    $report = RanReport::at(sprintf('%s/coverage.php', CoverageReports::all()['timed']), 3, RecordedBeside::nothing(), Path::of(...));

    expect(Outbreak::at($binary(), Scratch::directory())->outlining($report)->outline())->toEqual(NotWritten::because(sprintf(
        ReportAnswer::DECLINED,
        'expected the serialization format this reads at byte 0',
    )));
})->skip(fn(): bool => ! is_string(getenv('OUTBREAK_BINARY')), 'OUTBREAK_BINARY names no binary; the outbreak / boundary job builds one')
    ->group(...$holds);
