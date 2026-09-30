<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

$map = static fn(): CoverageMap => CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::subtracts'))
    ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('123'), Line::of(1), TestId::of('7'))
    ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.25))
    ->timed(TestId::of('IdleTest::waits'), Seconds::of(1.5));

$unreadable = CannotJudge::because('The coverage map is not one this gate writes, so no line of it can be read.');

// A map's file as data, to change one entry of and write back.
$written = static fn(array $file): string => Gzip::pack(Json::compact($file));
$file = static fn(): array => [
    'format' => 1,
    'tests' => [['id' => 'MoneyTest::adds', 'seconds' => 0.25], ['id' => 'MoneyTest::subtracts'], ['id' => 'IdleTest::waits', 'seconds' => 1.5]],
    'files' => ['src/Money.php' => ['3' => [0], '12' => [0, 1]]],
];

it('writes compact JSON, gzipped: each test once with its seconds, and each line\'s tests by their place, in the order covered', function () use ($map): void {
    expect(Gzip::unpack(CoverageMapFile::encode($map()), 'the map'))->toBe(Json::compact([
        'format' => 1,
        'tests' => [
            ['id' => 'MoneyTest::adds', 'seconds' => 0.25],
            ['id' => 'MoneyTest::subtracts'],
            ['id' => '7'],
            ['id' => 'IdleTest::waits', 'seconds' => 1.5],
        ],
        'files' => ['src/Money.php' => ['12' => [0, 1], '3' => [0]], '123' => ['1' => [2]]],
    ]));
});

it('writes an empty map as no tests and no files', function (): void {
    expect(Gzip::unpack(CoverageMapFile::encode(CoverageMap::empty()), 'the map'))->toBe('{"format":1,"tests":[],"files":{}}');
});

it('reads back the map it wrote', function () use ($map): void {
    expect(CoverageMapFile::decode(CoverageMapFile::encode($map())))->toEqual($map())
        ->and(CoverageMapFile::decode(CoverageMapFile::encode(CoverageMap::empty())))->toEqual(CoverageMap::empty());
});

it('writes a shard\'s map: only its files, and every test with its seconds', function () use ($map): void {
    $shard = CoverageMapFile::decode(CoverageMapFile::encode($map()->onlyFor(Paths::of(Path::of('123'), Path::of('src/Gone.php')))));

    expect($shard instanceof CoverageMap ? $shard->files() : Paths::none())->toEqual(Paths::of(Path::of('123')))
        ->and($shard instanceof CoverageMap ? $shard->durationOf(TestId::of('MoneyTest::adds')) : null)->toEqual(Seconds::of(0.25))
        ->and($shard instanceof CoverageMap ? $shard->durationOf(TestId::of('IdleTest::waits')) : null)->toEqual(Seconds::of(1.5));
});

it('cannot judge by a file that is not a map it writes', function (string $bytes) use ($unreadable): void {
    expect(CoverageMapFile::decode($bytes))->toEqual($unreadable);
})->with([
    'a map of another format' => [Gzip::pack('{"format": 2, "tests": [], "files": {}}')],
    'a format written as text' => [Gzip::pack('{"format": "1", "tests": [], "files": {}}')],
    'the map not gzipped' => ['{"format": 1, "tests": [], "files": {}}'],
    'text that is not JSON' => [Gzip::pack('{"format": 1, "tests": ')],
    'a gzip stream cut short' => [substr(Gzip::pack('{"format": 1, "tests": [], "files": {}}'), 0, 20)],
    'nothing' => [''],
]);

it('drops a line that is not well formed and keeps the rest', function (Closure $spoil) use ($file, $written): void {
    $spoilt = $file();
    $spoilt['files']['src/Money.php'] = $spoil($spoilt['files']['src/Money.php']);

    expect(CoverageMapFile::decode($written($spoilt)))->toEqual(CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 12, 'MoneyTest::adds', 'MoneyTest::subtracts'))->timedEach(
        TimedTest::of('MoneyTest::adds', 0.25),
        TimedTest::of('IdleTest::waits', 1.5),
    ));
})->with([
    'a line that is no number' => [static fn(array $lines): array => array_replace(array_diff_key($lines, [3 => true]), ['three' => $lines[3]])],
    'a line before the first' => [static fn(array $lines): array => array_replace(array_diff_key($lines, [3 => true]), [0 => $lines[3]])],
    'tests that are not a list' => [static fn(array $lines): array => array_replace($lines, [3 => 'MoneyTest::adds'])],
    'a place that is not a number' => [static fn(array $lines): array => array_replace($lines, [3 => ['0']])],
    'a place past the last test' => [static fn(array $lines): array => array_replace($lines, [3 => [0, 3]])],
    'a place before the first test' => [static fn(array $lines): array => array_replace($lines, [3 => [-1]])],
]);

it('drops a test that is not well formed, and every line that names it', function (array $test) use ($file, $written): void {
    $spoilt = $file();
    $spoilt['tests'][1] = $test;

    expect(CoverageMapFile::decode($written($spoilt)))->toEqual(CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 3, 'MoneyTest::adds'))->timedEach(
        TimedTest::of('MoneyTest::adds', 0.25),
        TimedTest::of('IdleTest::waits', 1.5),
    ));
})->with([
    'an id that is not text' => [['id' => 7]],
    'no id' => [['seconds' => 1.0]],
    'seconds that are not a number' => [['id' => 'MoneyTest::subtracts', 'seconds' => 'long']],
    'seconds below none' => [['id' => 'MoneyTest::subtracts', 'seconds' => -1.0]],
]);

it('reads tests and files that are not a list and a map as none', function () use ($file, $written): void {
    expect(CoverageMapFile::decode($written([...$file(), 'tests' => 'none', 'files' => 7])))->toEqual(CoverageMap::empty())
        ->and(CoverageMapFile::decode($written([...$file(), 'files' => ['src/Money.php' => 'none']])))->toEqual(CoverageMap::empty()->timedEach(
            TimedTest::of('MoneyTest::adds', 0.25),
            TimedTest::of('IdleTest::waits', 1.5),
        ));
});

it('writes and reads a map of hundreds of thousands of entries in linear time', function (): void {
    $covered = [];

    foreach (range(1, 1250) as $file) {
        foreach (range(1, 40) as $line) {
            $covered[] = CoveredLine::of(Path::of(sprintf('src/F%d.php', $file)), $line, sprintf('T%d::t', ($file + $line) % 3000), sprintf('T%d::t', ($file * $line) % 3000));
        }
    }

    $map = CoverageMap::of(...$covered)->timedEach(...array_map(static fn(int $test): TimedTest => TimedTest::of(sprintf('T%d::t', $test), 0.5), range(0, 2999)));
    $read = CoverageMap::empty();

    $seconds = Stopwatch::seconds(static function () use ($map, &$read): void {
        $read = CoverageMapFile::decode(CoverageMapFile::encode($map));
    });

    expect($read instanceof CoverageMap ? $read->files() : [])->toHaveCount(1250)
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});
