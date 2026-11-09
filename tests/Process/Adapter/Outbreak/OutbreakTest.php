<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\ProcessLoads;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\Coverage\TestLoad;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Outbreak/Outbreak.php',
    'holds:src/Adapter/Outbreak/Command.php',
    'holds:src/Adapter/Outbreak/ReportAsked.php',
    'holds:src/Adapter/Outbreak/ReportAnswer.php',
];

$slices = static fn(): MapSlices => MapSlices::of(
    MapSlice::of(Path::of('.mutation-gate/coverage/shard-1/map.json.gz'), Paths::of(Path::of('src/A.php'))),
    MapSlice::of(Path::of('.mutation-gate/coverage/verdict/map.json.gz'), Paths::none()),
);
$whole = static fn(): Path => Path::of('.mutation-gate/coverage/map.json.gz');
$report = static fn(): RanReport => RanReport::at(
    '/project/coverage.php',
    4,
    RecordedBeside::of(
        LoadedPackages::of(ProcessLoads::of(TestLoad::of('T\A::a', 'vendor/a'), TestLoad::of('T\C::c')->startingProcesses())),
        TimedTest::of('T\A::a', 0.5),
        TimedTest::of('T\C::c', 2.0),
    ),
    static fn(string $file): Path => Path::of(substr($file, strlen('/project/'))),
);

it('vouches for a map only where the binary says so in its contract, handing it the text', function (): void {
    $vouching = OutbreakStandIn::answering('{"outbreak":1,"vouched":true}');
    $engine = Outbreak::at($vouching->binary(), '/project');

    expect($engine->isInUse())->toBeTrue()
        ->and($engine->vouches(Contents::of('{"format":1}')))->toBe(MapVouch::Vouched)
        ->and($vouching->read())->toBe('{"format":1}');

    foreach (['{"outbreak":1,"vouched":false,"why":"x"}', '{"outbreak":1}', '{"outbreak":1,"vouched":"yes"}', 'not json'] as $answer) {
        expect(Outbreak::at(OutbreakStandIn::answering($answer)->binary(), '/project')->vouches(Contents::of('{}')))
            ->toBe(MapVouch::Unvouched);
    }
})->group(...$holds);

it('vouches for nothing where the binary ends badly or answers in another contract', function (): void {
    $failing = Outbreak::at(OutbreakStandIn::answering('{"outbreak":1,"vouched":true}', exit: 1)->binary(), '/project');
    $other = Outbreak::at(OutbreakStandIn::answering('{"outbreak":2,"vouched":true}')->binary(), '/project');

    expect($failing->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched)
        ->and($other->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched);
})->group(...$holds);

it('asks a binary its contract with nothing on its input', function (): void {
    $asked = OutbreakStandIn::answering('{"outbreak":1}');
    Outbreak::at($asked->binary(), '/project');

    expect($asked->arguments())->toBe('contract')
        ->and($asked->read())->toBe('');
})->group(...$holds);

it('uses no binary that speaks another contract, cannot say which, or cannot start', function (): void {
    expect(Outbreak::at(OutbreakStandIn::answering('', contract: '{"outbreak":2}')->binary(), '/p')->isInUse())->toBeFalse()
        ->and(Outbreak::at(OutbreakStandIn::answering('', contract: 'nothing')->binary(), '/p')->isInUse())->toBeFalse()
        ->and(Outbreak::at(sprintf('%s/absent', Scratch::directory()), '/p')->isInUse())->toBeFalse()
        ->and(Outbreak::none('/p')->isInUse())->toBeFalse()
        ->and(Outbreak::none('/p')->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched);
})->group(...$holds);

it('asks the binary to split the whole map into each slice, by paths from the project\'s root', function () use ($slices, $whole): void {
    $splitting = OutbreakStandIn::answering('{"outbreak":1,"written":2}');

    expect(Outbreak::at($splitting->binary(), '/project')->split($whole(), $slices()))->toEqual(Written::noting(
        '.mutation-gate/coverage/map.json.gz',
        'Outbreak wrote 2 coverage maps from .mutation-gate/coverage/map.json.gz.',
    ))->and(json_decode($splitting->read(), associative: true))->toBe([
        'whole' => '/project/.mutation-gate/coverage/map.json.gz',
        'slices' => [
            ['into' => '/project/.mutation-gate/coverage/shard-1/map.json.gz', 'files' => ['src/A.php']],
            ['into' => '/project/.mutation-gate/coverage/verdict/map.json.gz', 'files' => []],
        ],
    ]);
})->group(...$holds);

it('splits on the threads the settings give, and on every core where they give none', function () use ($slices, $whole): void {
    $splitting = OutbreakStandIn::answering('{"outbreak":1,"written":2}');
    Outbreak::at($splitting->binary(), '/project', OutbreakSettings::of(threads: 3))->split($whole(), $slices());
    $given = $splitting->arguments();
    Outbreak::at($splitting->binary(), '/project')->split($whole(), $slices());

    expect($given)->toBe('map split --threads 3')
        ->and($splitting->arguments())->toBe('map split');
})->group(...$holds);

it('says why the binary wrote no slice: it declined, failed, answered otherwise, or there is none', function () use ($slices, $whole): void {
    $split = static fn(string $answer, int $exit = 0): Written|NotWritten => Outbreak::at(
        OutbreakStandIn::answering($answer, $exit)->binary(),
        '/project',
    )->split($whole(), $slices());

    expect($split('{"outbreak":1,"declined":"expected a compact map at byte 0"}'))
        ->toEqual(NotWritten::because('Outbreak declined to split the map: expected a compact map at byte 0'))
        ->and($split('{"outbreak":1,"written":"two"}'))
        ->toEqual(NotWritten::because('Outbreak did not split the map: it answered {"outbreak":1,"written":"two"}'))
        ->and($split('{"outbreak":1}'))
        ->toEqual(NotWritten::because('Outbreak did not split the map: it answered {"outbreak":1}'))
        ->and($split('broken', exit: 1))
        ->toEqual(NotWritten::because('Outbreak did not split the map: broken'))
        ->and(Outbreak::none('/project')->split($whole(), $slices()))
        ->toEqual(NotWritten::because('No Outbreak binary is in use.'));
})->group(...$holds);

it('vouches for and splits nothing where the binary runs past its time', function () use ($slices, $whole): void {
    $root = Scratch::directory();
    $slow = sprintf('%s/slow', $root);
    file_put_contents($slow, "#!/bin/sh\nif [ \"$1\" = contract ]; then echo '{\"outbreak\":1}'; exit 0; fi\nsleep 30\n");
    chmod($slow, 0o755);
    $engine = Outbreak::at($slow, $root, OutbreakSettings::of(timeout: Seconds::of(3)));

    expect($engine->isInUse())->toBeTrue()
        ->and($engine->vouches(Contents::of('{}')))->toBe(MapVouch::Unvouched)
        ->and($engine->split($whole(), $slices()))->toBeInstanceOf(NotWritten::class);
})->group(...$holds);

it('runs the binary a config names, from the root where relative, and none where it is off', function (): void {
    $root = Scratch::directory();
    $standIn = OutbreakStandIn::answering('{"outbreak":1,"vouched":true}');
    rename($standIn->binary(), sprintf('%s/outbreak', $root));
    $shipped = Scratch::directory();

    expect(Outbreak::configured(OutbreakSettings::of(binary: 'outbreak'), $root, $shipped)->isInUse())->toBeTrue()
        ->and(Outbreak::configured(OutbreakSettings::of(binary: sprintf('%s/outbreak', $root)), '/elsewhere', $shipped)->isInUse())
        ->toBeTrue()
        ->and(Outbreak::configured(OutbreakSettings::of(enabled: false, binary: 'outbreak'), $root, $shipped)->isInUse())
        ->toBeFalse()
        ->and(Outbreak::configured(OutbreakSettings::of(), $root, $shipped)->isInUse())->toBeFalse();
})->group(...$holds);

it('reads the settings beneath a runner\'s options, and the defaults where they hold none', function (): void {
    $root = Scratch::directory();
    $standIn = OutbreakStandIn::answering('{"outbreak":1,"vouched":true}');
    rename($standIn->binary(), sprintf('%s/outbreak', $root));
    $beneath = static fn(bool $enabled): Options => Options::of(Json::object(Member::of(
        OutbreakSettings::OPTION,
        Json::object(
            Member::of(OutbreakSettings::ENABLED, $enabled),
            Member::of(OutbreakSettings::BINARY, 'outbreak'),
            Member::of(OutbreakSettings::TIMEOUT, 90.0),
            Member::of(OutbreakSettings::THREADS, 2),
        ),
    )));
    $engine = Outbreak::beneath($beneath(enabled: true), $root, Scratch::directory());
    $engine->split(Path::of('whole.json.gz'), MapSlices::of());
    $given = file_get_contents(sprintf('%s/outbreak.arguments', $root));

    expect($engine)->toEqual(Outbreak::at(
        sprintf('%s/outbreak', $root),
        $root,
        OutbreakSettings::of(binary: 'outbreak', timeout: Seconds::of(90.0), threads: 2),
    ))->and($given)->toBe('map split --threads 2')
        ->and(Outbreak::beneath($beneath(enabled: true), $root, Scratch::directory())->isInUse())->toBeTrue()
        ->and(Outbreak::beneath($beneath(enabled: false), $root, Scratch::directory())->isInUse())->toBeFalse()
        ->and(Outbreak::beneath(Options::of(Json::object()), $root, Scratch::directory())->isInUse())->toBeFalse();
})->group(...$holds);

it('asks the binary for a report\'s outline, handing it the report, its format and the tests timed beside it', function () use ($report): void {
    $outlining = OutbreakStandIn::answering(
        '{"outbreak":1,"outline":{"files":["/project/src/A.php","/project/src/B.php"],"tests":[["T\\\\A::a",[1,0]],["T\\\\B::b",[]]]}}',
    );
    $a = Path::of('src/A.php');
    $b = Path::of('src/B.php');

    expect(Outbreak::at($outlining->binary(), '/project')->outlining($report())->outline())->toEqual(MapOutline::listed(
        TestIds::of(TestId::of('T\A::a'), TestId::of('T\B::b')),
        ['T\A::a' => ['src/B.php' => $b, 'src/A.php' => $a], 'T\B::b' => []],
        ['/project/src/A.php' => $a, '/project/src/B.php' => $b],
    ))->and($outlining->arguments())->toBe('report outline')
        ->and($outlining->read())->toBe('{"report":"/project/coverage.php","format":4,"timed":[["T\\\\A::a",0.5],["T\\\\C::c",2.0]]}');
})->group(...$holds);

it('says why the binary outlined no report: it declined, failed, answered otherwise, or there is none', function () use ($report): void {
    $outline = static fn(string $answer, int $exit = 0): MapOutline|NotWritten => Outbreak::at(
        OutbreakStandIn::answering($answer, $exit)->binary(),
        '/project',
    )->outlining($report())->outline();

    expect($outline('{"outbreak":1,"declined":"expected UTF-8 at byte 9"}'))
        ->toEqual(NotWritten::because('Outbreak declined to read the report: expected UTF-8 at byte 9'))
        ->and($outline('{"outbreak":1,"outline":{"files":[],"tests":[["T\\\\A::a",[0]]]}}'))
        ->toEqual(NotWritten::because('Outbreak did not read the report: it answered {"outbreak":1,"outline":{"files":[],"tests":[["T\\\\A::a",[0]]]}}'))
        ->and($outline('{"outbreak":1}'))
        ->toEqual(NotWritten::because('Outbreak did not read the report: it answered {"outbreak":1}'))
        ->and($outline('broken', exit: 1))
        ->toEqual(NotWritten::because('Outbreak did not read the report: broken'))
        ->and(Outbreak::none('/project')->outlining($report())->outline())
        ->toEqual(NotWritten::because('No Outbreak binary is in use.'))
        ->and(Outbreak::none('/project')->outlining($report())->noneAtOnce())
        ->toEqual(NotWritten::because('No Outbreak binary is in use.'));
})->group(...$holds);

it('asks the binary to write a report\'s map as the gate decides it, by paths from the project\'s root', function () use ($report): void {
    $converting = OutbreakStandIn::answering('{"outbreak":1,"written":1}');
    $outline = MapOutline::listed(TestIds::of(TestId::of('T\A::a')), [], ['/project/src/A.php' => Path::of('src/A.php')]);
    $conversion = Conversion::of(
        $outline,
        Admission::of(['T\A::a' => ['src/A.php' => true]]),
        MeasuredAt::of(Revision::ref('abc'), dirty: false),
        EntryKeys::none()->with(Path::of('tests/ATest.php'), Digest::of('d')),
    );

    expect(Outbreak::at($converting->binary(), '/project')->convert($report(), $conversion, Path::of('coverage/map.json.gz')))
        ->toEqual(Written::to('/project/coverage/map.json.gz'))
        ->and($converting->arguments())->toBe('report convert')
        ->and(json_decode($converting->read(), associative: true))->toBe([
            'report' => '/project/coverage.php',
            'format' => 4,
            'into' => '/project/coverage/map.json.gz',
            'paths' => ['/project/src/A.php' => 'src/A.php'],
            'timed' => [['T\A::a', 0.5], ['T\C::c', 2.0]],
            'loads' => [[['T\A::a', ['vendor/a'], false], ['T\C::c', [], true]]],
            'leaving' => ['T\A::a' => ['src/A.php']],
            'measured' => ['commit' => 'abc', 'dirty' => false],
            'keys' => ['tests/ATest.php' => 'd'],
        ]);
})->group(...$holds);

it('asks for a map admitted whole, unplaced and unkeyed with no such fields, and empty objects where it names nothing', function () use ($report): void {
    $converting = OutbreakStandIn::answering('{"outbreak":1,"written":1}');
    $conversion = Conversion::of(MapOutline::listed(TestIds::of(), [], []), NotGiven::value(), Unplaced::map(), NotGiven::value());
    Outbreak::at($converting->binary(), '/project')->convert($report(), $conversion, Path::of('map.json.gz'));

    expect($converting->read())->toContain('"paths":{},')
        ->and(json_decode($converting->read(), associative: true))->toBe([
            'report' => '/project/coverage.php',
            'format' => 4,
            'into' => '/project/map.json.gz',
            'paths' => [],
            'timed' => [['T\A::a', 0.5], ['T\C::c', 2.0]],
            'loads' => [[['T\A::a', ['vendor/a'], false], ['T\C::c', [], true]]],
        ]);
})->group(...$holds);

it('says why the binary wrote no map of a report: it declined, failed, answered otherwise, or there is none', function () use ($report): void {
    $conversion = Conversion::of(MapOutline::listed(TestIds::of(), [], []), NotGiven::value(), Unplaced::map(), NotGiven::value());
    $convert = static fn(string $answer, int $exit = 0): Written|NotWritten => Outbreak::at(
        OutbreakStandIn::answering($answer, $exit)->binary(),
        '/project',
    )->convert($report(), $conversion, Path::of('map.json.gz'));

    expect($convert('{"outbreak":1,"declined":"expected a path for each file at byte 0"}'))
        ->toEqual(NotWritten::because('Outbreak declined to read the report: expected a path for each file at byte 0'))
        ->and($convert('{"outbreak":1,"written":2}'))
        ->toEqual(NotWritten::because('Outbreak did not read the report: it answered {"outbreak":1,"written":2}'))
        ->and($convert('{"outbreak":1,"written":"one"}'))
        ->toEqual(NotWritten::because('Outbreak did not read the report: it answered {"outbreak":1,"written":"one"}'))
        ->and($convert('broken', exit: 1))
        ->toEqual(NotWritten::because('Outbreak did not read the report: broken'))
        ->and(Outbreak::none('/project')->convert($report(), $conversion, Path::of('map.json.gz')))
        ->toEqual(NotWritten::because('No Outbreak binary is in use.'));
})->group(...$holds);

it('starts the binary on a report\'s outline at once and waits for its answer only when the outline is taken', function () use ($report): void {
    $root = Scratch::directory();
    $waiting = sprintf('%s/waiting', $root);
    $go = sprintf('%s/go', $root);
    file_put_contents($waiting, sprintf(
        "#!/bin/sh\nif [ \"$1\" = contract ]; then echo '{\"outbreak\":1}'; exit 0; fi\nwhile [ ! -f %s ]; do sleep 0.1; done\necho '{\"outbreak\":1,\"outline\":{\"files\":[],\"tests\":[]}}'\n",
        $go,
    ));
    chmod($waiting, 0o755);
    $pending = Outbreak::at($waiting, $root, OutbreakSettings::of(timeout: Seconds::of(30)))->outlining($report());
    $none = $pending->noneAtOnce();
    touch($go);

    expect($none)->toEqual(NotGiven::value())
        ->and($pending->outline())->toEqual(MapOutline::listed(TestIds::of(), [], []));
})->group(...$holds);
