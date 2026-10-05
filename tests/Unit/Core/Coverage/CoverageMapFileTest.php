<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethods;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\LineTests;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Growth;

$map = static fn(): CoverageMap => CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::subtracts'))
    ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('123'), Line::of(1), TestId::of('7'))
    ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.25))
    ->timed(TestId::of('IdleTest::waits'), Seconds::of(1.5))
    ->executing(Path::of('src/Money.php'), ExecutedMethod::of('add', 10, 13), ExecutedMethod::of('list', 15, 15));

$unreadable = CannotJudge::because('The coverage map is not one this gate writes, so no line of it can be read.');

// A map's file as data, to change one entry of and write back.
$written = static fn(array $file): string => Gzip::pack(JsonText::compact($file));
$file = static fn(): array => [
    'format' => 1,
    'tests' => [['id' => 'MoneyTest::adds', 'seconds' => 0.25], ['id' => 'MoneyTest::subtracts'], ['id' => 'IdleTest::waits', 'seconds' => 1.5]],
    'files' => ['src/Money.php' => ['3' => [0], '12' => [0, 1]]],
];

it('writes compact JSON, gzipped: each test once with its seconds, and each line\'s tests by their place, in the order covered', function () use ($map): void {
    expect(Gzip::unpack(CoverageMapFile::encode($map(), Unplaced::map()), 'the map'))->toBe(JsonText::compact([
        'format' => 1,
        'tests' => [
            ['id' => 'MoneyTest::adds', 'seconds' => 0.25],
            ['id' => 'MoneyTest::subtracts'],
            ['id' => '7'],
            ['id' => 'IdleTest::waits', 'seconds' => 1.5],
        ],
        'files' => ['src/Money.php' => ['12' => [0, 1], '3' => [0]], '123' => ['1' => [2]]],
        'methods' => ['src/Money.php' => [['name' => 'add', 'start' => 10, 'end' => 13], ['name' => 'list', 'start' => 15, 'end' => 15]]],
    ]));
});

it('reads a test listed twice as one, wherever a line names either place', function () use ($written): void {
    $map = CoverageMapFile::decode($written([
        'format' => 1,
        'tests' => [['id' => 'MoneyTest::adds', 'seconds' => 0.25], ['id' => 'MoneyTest::subtracts'], ['id' => 'MoneyTest::adds']],
        'files' => ['src/Money.php' => ['3' => [2], '12' => [0, 1, 2]]],
    ]));
    $ids = static fn(TestIds $tests): array => array_map(static fn(TestId $test): string => $test->value(), [...$tests]);

    expect($map instanceof CoverageMap ? $ids($map->tests()) : [])->toBe(['MoneyTest::adds', 'MoneyTest::subtracts'])
        ->and($map instanceof CoverageMap ? $ids($map->testsCovering(Path::of('src/Money.php'), Line::of(3))) : [])->toBe(['MoneyTest::adds'])
        ->and($map instanceof CoverageMap ? $ids($map->testsCovering(Path::of('src/Money.php'), Line::of(12))) : [])->toBe(['MoneyTest::adds', 'MoneyTest::subtracts']);
});

it('is map.json.gz in the directory a job hands on', function (): void {
    expect(CoverageMapFile::in(Path::of('.mutation-gate/coverage/shard-2')))
        ->toEqual(Path::of('.mutation-gate/coverage/shard-2/map.json.gz'));
});

it('writes an empty map as no tests and no files', function (): void {
    expect(Gzip::unpack(CoverageMapFile::encode(CoverageMap::empty(), Unplaced::map()), 'the map'))->toBe('{"format":1,"tests":[],"files":{}}');
});

it('reads back the map it wrote', function () use ($map): void {
    expect(CoverageMapFile::decode(CoverageMapFile::encode($map(), Unplaced::map())))->toEqual($map())
        ->and(CoverageMapFile::decode(CoverageMapFile::encode(CoverageMap::empty(), Unplaced::map())))->toEqual(CoverageMap::empty());
});

it('writes a shard\'s map: only its files, and every test with its seconds', function () use ($map): void {
    $shard = CoverageMapFile::decode(CoverageMapFile::encode($map()->onlyFor(Paths::of(Path::of('123'), Path::of('src/Gone.php'))), Unplaced::map()));

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

it('reads tests and files that are not a list and a map as none, and knows each listed test though it covers no line', function () use ($file, $written): void {
    $listed = LineTests::placed(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::subtracts'), TestId::of('IdleTest::waits')));

    expect(CoverageMapFile::decode($written([...$file(), 'tests' => 'none', 'files' => 7])))->toEqual(CoverageMap::empty())
        ->and(CoverageMapFile::decode($written([...$file(), 'files' => ['src/Money.php' => 'none']])))->toEqual(CoverageMap::placed(
            $listed,
            TimedTest::of('MoneyTest::adds', 0.25),
            TimedTest::of('IdleTest::waits', 1.5),
        ));
});

it('writes and reads a map in time linear in its entries', function (): void {
    $read = static function (int $size): Closure {
        $covered = [];

        foreach (range(1, $size) as $file) {
            foreach (range(1, 40) as $line) {
                $covered[] = CoveredLine::of(Path::of(sprintf('src/F%d.php', $file)), $line, sprintf('T%d::t', ($file + $line) % 3000), sprintf('T%d::t', ($file * $line) % 3000));
            }
        }

        $map = CoverageMap::of(...$covered)->timedEach(...array_map(static fn(int $test): TimedTest => TimedTest::of(sprintf('T%d::t', $test), 0.5), range(0, $size - 1)));

        return static fn(): CoverageMap|CannotJudge => CoverageMapFile::decode(CoverageMapFile::encode($map, Unplaced::map()));
    };
    $few = $read(10)();

    expect($few instanceof CoverageMap ? $few->files() : [])->toHaveCount(10)
        ->and(Growth::of(150, $read))->toBeLessThan(Growth::LINEAR);
});

it('is found in the directory a job hands on, and says so where the gate wrote none', function (): void {
    expect(CoverageMapFile::in(Path::of('.mutation-gate/coverage')))->toEqual(Path::of('.mutation-gate/coverage/map.json.gz'))
        ->and(CoverageMapFile::missingAt('/work/nowhere/map.json.gz'))->toEqual(CannotJudge::because(
            'The gate wrote no coverage map at /work/nowhere/map.json.gz, and reads no runner\'s map another job wrote.',
        ));
});

it('keeps a shard\'s files\' executed methods, and reads a map without methods as one with none', function () use ($map, $file, $written): void {
    $shard = CoverageMapFile::decode(CoverageMapFile::encode($map()->onlyFor(Paths::of(Path::of('123'))), Unplaced::map()));
    $whole = CoverageMapFile::decode($written($file()));

    expect($shard instanceof CoverageMap ? $shard->methods()->paths() : $shard)->toEqual(Paths::none())
        ->and($whole instanceof CoverageMap ? $whole->methods()->at(Path::of('src/Money.php'), ExecutedMethods::none()) : $whole)->toEqual(ExecutedMethods::none());
});

it('drops a method that is not well formed and keeps the rest', function (mixed $method) use ($file, $written): void {
    $kept = ['name' => 'add', 'start' => 10, 'end' => 13];
    $map = CoverageMapFile::decode($written([...$file(), 'methods' => ['src/Money.php' => [$method, $kept], 'src/Other.php' => 'none']]));

    expect($map instanceof CoverageMap ? $map->methods()->at(Path::of('src/Money.php'), ExecutedMethods::none()) : $map)
        ->toEqual(ExecutedMethods::of(ExecutedMethod::of('add', 10, 13)))
        ->and($map instanceof CoverageMap ? $map->methods()->at(Path::of('src/Other.php'), ExecutedMethods::none()) : $map)->toEqual(ExecutedMethods::none());
})->with([
    'no name' => [['start' => 1, 'end' => 2]],
    'an empty name' => [['name' => '', 'start' => 1, 'end' => 2]],
    'a start before the file' => [['name' => 'x', 'start' => 0, 'end' => 2]],
    'an end before its start' => [['name' => 'x', 'start' => 3, 'end' => 2]],
    'a start that is text' => [['name' => 'x', 'start' => '1', 'end' => 2]],
    'no end' => [['name' => 'x', 'start' => 1]],
    'not a method' => ['add'],
]);

it('reads a method of one line', function () use ($file, $written): void {
    $map = CoverageMapFile::decode($written([...$file(), 'methods' => ['src/Money.php' => [['name' => 'one', 'start' => 1, 'end' => 1]]]]));

    expect($map instanceof CoverageMap ? $map->methods()->at(Path::of('src/Money.php'), ExecutedMethods::none()) : $map)
        ->toEqual(ExecutedMethods::of(ExecutedMethod::of('one', 1, 1)));
});

it('writes each line no test ran with no tests, and reads it back as one', function () use ($map): void {
    $missing = CoverageMap::of(...[...$map()->lines(), CoveredLine::of(Path::of('src/Money.php'), 4), CoveredLine::of(Path::of('src/Gone.php'), 2)])
        ->timedEach(TimedTest::of('MoneyTest::adds', 0.25));
    $written = CoverageMapFile::encode($missing, Unplaced::map());
    $read = CoverageMapFile::decode($written);
    $json = Gzip::unpack($written, 'the map');

    expect($json instanceof CannotJudge ? $json->why() : $json)->toContain('"src/Money.php":{"12":[0,1],"3":[0],"4":[]}')
        ->and($json instanceof CannotJudge ? $json->why() : $json)->toContain('"src/Gone.php":{"2":[]}')
        ->and($read instanceof CoverageMap ? [...$read->linesMissed(Path::of('src/Money.php'))] : $read)->toEqual([Line::of(4)])
        ->and($read instanceof CoverageMap ? [...$read->linesMissed(Path::of('src/Gone.php'))] : $read)->toEqual([Line::of(2)]);
});

it('keeps a map with where it was measured and each test file\'s entry key, and reads all three back as data', function () use ($map): void {
    $at = MeasuredAt::of(Revision::ref(str_repeat('a', 40)), dirty: false);
    $keys = EntryKeys::none()
        ->with(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money'))
        ->with(Path::of('123'), Digest::sha256Of('numbered'));
    $bytes = CoverageMapFile::keeping(KeptMap::of($map(), $at, $keys), MapLimits::standard());
    $kept = is_string($bytes) ? CoverageMapFile::kept($bytes, MapLimits::standard()) : $bytes;

    expect($kept)->toEqual(KeptMap::of($map(), $at, $keys))
        ->and(Gzip::unpack(is_string($bytes) ? $bytes : '', 'the map'))->toContain(sprintf(
            '"keys":%s',
            JsonText::compact(['123' => Digest::sha256Of('numbered')->value(), 'tests/MoneyTest.php' => Digest::sha256Of('money')->value()]),
        ))
        ->and(CoverageMapFile::decode(CoverageMapFile::encode($map(), $at, EntryKeys::none())))->toEqual($map());
});

it('reads a kept map\'s keys that are no path and digest as none, and a map with no keys as keying no file', function () use ($file, $written): void {
    $kept = CoverageMapFile::kept($written([...$file(), 'keys' => [
        'tests/MoneyTest.php' => str_repeat('b', 64),
        'tests/Short.php' => 'abc',
        'tests/Listed.php' => [str_repeat('c', 64)],
        '' => str_repeat('d', 64),
    ]]), MapLimits::standard());
    $unkeyed = CoverageMapFile::kept($written($file()), MapLimits::standard());
    $listed = CoverageMapFile::kept($written([...$file(), 'keys' => [str_repeat('e', 64)]]), MapLimits::standard());

    expect($kept instanceof KeptMap ? $kept->keys() : null)
        ->toEqual(EntryKeys::none()->with(Path::of('tests/MoneyTest.php'), Digest::of(str_repeat('b', 64))))
        ->and($unkeyed instanceof KeptMap ? $unkeyed->keys() : null)->toEqual(EntryKeys::none())
        ->and($unkeyed instanceof KeptMap ? $unkeyed->measuredAt() : null)->toEqual(Unplaced::map())
        ->and($listed instanceof KeptMap ? $listed->keys() : null)
        ->toEqual(EntryKeys::none()->with(Path::of('0'), Digest::of(str_repeat('e', 64))));
});

it('keeps no map past either of a store\'s limits, and reads none that inflates past it, is no gzip, or is not this format', function () use ($map, $file, $written, $unreadable): void {
    $kept = KeptMap::of($map(), Unplaced::map(), EntryKeys::none());
    $bytes = CoverageMapFile::keeping($kept, MapLimits::standard());
    $packed = is_string($bytes) ? strlen($bytes) : 0;
    $unpacked = Gzip::unpack(is_string($bytes) ? $bytes : '', 'the map');
    $text = is_string($unpacked) ? strlen($unpacked) : 0;

    expect(CoverageMapFile::keeping($kept, MapLimits::of($packed - 1, $text)))
        ->toEqual(CannotJudge::because(sprintf('The coverage map is %d bytes packed and %d bytes as text, over what a store keeps.', $packed, $text)))
        ->and(CoverageMapFile::keeping($kept, MapLimits::of($packed, $text - 1)))->toBeInstanceOf(CannotJudge::class)
        ->and(CoverageMapFile::keeping($kept, MapLimits::of($packed, $text)))->toBe($bytes)
        ->and(CoverageMapFile::kept(is_string($bytes) ? $bytes : '', MapLimits::of($packed, $text - 1)))
        ->toEqual(CannotJudge::because(sprintf('The coverage map inflates to more than %d bytes.', $text - 1)))
        ->and(CoverageMapFile::kept('not gzip', MapLimits::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and(CoverageMapFile::kept($written([...$file(), 'format' => 2]), MapLimits::standard()))->toEqual($unreadable);
});
