<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\Coverage\VouchedRead;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\ConvertsPhpReports;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;
use NightWorksIO\MutationGate\Tests\Fakes\MapEngineFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageReports;
use NightWorksIO\MutationGate\Tests\Support\Gunzipped;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\VouchedMaps;

// What every map engine answers (ADR-0118): it vouches for no text the
// careful reading would drop an entry of, a text it vouches for is built into
// the map the careful reading builds, and every slice it writes holds the text
// the gate writes; and one that converts a run's PHP report outlines and writes
// the map the gate builds of it (ADR-0119). Outbreak's core is the binary OUTBREAK_BINARY
// names, which the `outbreak / boundary` job builds; with none, it declines
// everything.

$holds = [
    'holds:src/Adapter/Outbreak/Outbreak.php',
    'holds:src/Core/Coverage/VouchedRead.php',
    'holds:src/Core/Coverage/MapSlices.php',
    'holds:src/Adapter/Outbreak/ReportAsked.php',
    'holds:src/Adapter/Outbreak/ReportAnswer.php',
];

$engines = ['the fake', 'Outbreak\'s core'];

/** The engine of a name, in a project at this root. */
$engineAt = static fn(string $name, string $root): ConvertsPhpReports&MapEngine => match ($name) {
    'the fake' => new MapEngineFake(array_column(VouchedMaps::texts(), 0), $root),
    default => is_string(getenv('OUTBREAK_BINARY')) ? Outbreak::at((string) getenv('OUTBREAK_BINARY'), $root) : Outbreak::none($root),
};

it('builds a text it vouches for into the map the careful reading builds', function (string $name) use ($engineAt): void {
    $maps = $engineAt($name, Scratch::directory());

    foreach (VouchedMaps::texts() as $name => [$json]) {
        $vouch = $maps->vouches(Contents::of($json));
        $built = $vouch === MapVouch::Vouched ? VouchedMaps::written(VouchedRead::read($json)) : VouchedMaps::carefully($json);

        expect($built)->toBe(VouchedMaps::carefully($json), $name);
    }
})->with($engines)->group(...$holds);

it('vouches for no text the careful reading drops an entry of', function (string $name) use ($engineAt): void {
    $maps = $engineAt($name, Scratch::directory());

    foreach (VouchedMaps::dropping() as $name => [$json]) {
        expect($maps->vouches(Contents::of($json)))->toBe(MapVouch::Unvouched, $name);
    }
})->with($engines)->group(...$holds);

it('writes each slice it writes as the gate writes the map for its files, and says why where it writes none', function (string $name) use ($engineAt): void {
    $files = [
        'shard-1/map.json.gz' => Paths::of(Path::of('src/A.php')),
        'shard-2/map.json.gz' => Paths::none(),
        'verdict/map.json.gz' => Paths::of(Path::of('src/B.php'), Path::of('src/A.php'), Path::of('123')),
    ];
    $slices = MapSlices::of();

    foreach ($files as $into => $paths) {
        $slices = $slices->with(MapSlice::of(Path::of(sprintf('coverage/%s', $into)), $paths));
    }

    foreach (VouchedMaps::texts() as $held => [$json]) {
        $root = Scratch::directory();
        $map = CoverageMapFile::decode(Gzip::pack($json), HandedMaps::limits());
        $text = MapText::of($map instanceof CoverageMap ? $map : CoverageMap::of());
        Scratch::write($root, 'coverage/map.json.gz', CoverageMapFile::encode($text, Unplaced::map()));
        $written = $engineAt($name, $root)->split(Path::of('coverage/map.json.gz'), $slices);

        expect($written instanceof NotWritten ? $written->why() : 'written')->not->toBe('', $held);

        foreach ($written instanceof Written ? $files : [] as $into => $paths) {
            expect(Gunzipped::of((string) file_get_contents(sprintf('%s/coverage/%s', $root, $into))))
                ->toBe($text->onlyFor($paths)->written(Unplaced::map(), NotGiven::value()), sprintf('%s: %s', $held, $into));
        }
    }
})->with($engines)->group(...$holds);

it('outlines each report as the map the gate builds of it, naming each file as the report does', function (string $name) use ($engineAt): void {
    foreach (CoverageReports::all() as $held => $directory) {
        $report = CoverageReports::ran($directory);
        $outline = $engineAt($name, Scratch::directory())->outlining($report)->outline();

        foreach ($outline instanceof MapOutline ? [$outline] : [] as $read) {
            expect(CoverageReports::outlined($read))
                ->toBe(CoverageReports::outlined(MapOutline::of(CoverageReports::map($directory))), $held);

            foreach ($read->named() as $file => $path) {
                expect($path)->toEqual($report->relative($file), $held);
            }
        }

        expect($outline instanceof NotWritten ? $outline->why() : 'outlined')->not->toBe('', $held);
    }
})->with($engines)->group(...$holds);

it('writes the map of each report as the gate writes it, admitted, measured and keyed as the gate decides', function (string $name) use ($engineAt): void {
    foreach (CoverageReports::all() as $held => $directory) {
        foreach (array_unique([false, array_key_exists($held, CoverageReports::admitting())]) as $admitted) {
            $root = Scratch::directory();
            $engine = $engineAt($name, $root);
            $report = CoverageReports::ran($directory);
            $outline = $engine->outlining($report)->outline();
            $written = $outline instanceof MapOutline
                ? $engine->convert($report, CoverageReports::conversion($directory, $outline, admitted: $admitted), Path::of('coverage/map.json.gz'))
                : $outline;

            expect($written instanceof NotWritten ? $written->why() : 'written')->not->toBe('', $held);

            foreach ($written instanceof Written ? [$written] : [] as $_) {
                expect(Gunzipped::of((string) file_get_contents(sprintf('%s/coverage/map.json.gz', $root))))
                    ->toBe(CoverageReports::written($directory, admitted: $admitted), sprintf('%s, admitted: %s', $held, var_export($admitted, return: true)));
            }
        }
    }
})->with($engines)->group(...$holds);

it('says why it reads no report where there is none', function (string $name) use ($engineAt): void {
    $root = Scratch::directory();
    $report = RanReport::at(sprintf('%s/absent.php', $root), 4, RecordedBeside::nothing(), Path::of(...));
    $outline = MapOutline::listed(TestIds::of(), [], []);
    $engine = $engineAt($name, $root);
    $conversion = Conversion::of($outline, NotGiven::value(), Unplaced::map(), NotGiven::value());

    expect($engine->outlining($report)->outline())->toBeInstanceOf(NotWritten::class)
        ->and($engine->convert($report, $conversion, Path::of('map.json.gz')))->toBeInstanceOf(NotWritten::class)
        ->and(file_exists(sprintf('%s/map.json.gz', $root)))->toBeFalse();
})->with($engines)->group(...$holds);
