<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinition;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Proof\Key\Judging;
use NightWorksIO\MutationGate\Core\Proof\Key\Source;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Proof\Key\Tests;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Growth;

const CONTENT_KEY_SOURCE = [
    'src/B.php' => 'b1',
    'src/A.php' => 'a1',
    'composer.json' => 'c1',
    'mutation-gate.json' => 'g1',
    'mutation-gate-baseline.json' => 'l1',
    'docs/index.md' => 'd1',
    '.github/workflows/ci.yml' => 'w1',
    '.github/workflows/mutation.yml' => 'm1',
];

const CONTENT_KEY_CI = "name: mutation\n# The gate.\nsteps:\n  - uses: actions/checkout@3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d # v7\n";

const CONTENT_KEY_CASES = [
    'tests/Unit/MoneyTest.php' => ['m1', "<?php\nit('adds', fn() => new Helper());\n"],
    'tests/Unit/UnknownTest.php' => ['u1', "<?php\nit('works');\n"],
    'tests/Unit/OtherTest.php' => ['o1', "<?php\nit('other', fn() => new Unused());\n"],
    'tests/Unit/CanaryTest.php' => ['k1', "<?php\nit('sings');\n"],
];

const CONTENT_KEY_OTHERS = [
    'tests/Pest.php' => ['p1', "<?php\nuses(Base::class);\n"],
    'tests/Support/Helper.php' => ['h1', "<?php\nfinal class Helper {}\n"],
    'tests/Support/Base.php' => ['b2', "<?php\nabstract class Base {}\n"],
    'tests/Support/Unused.php' => ['x1', "<?php\nfinal class Unused {}\n"],
];

const CONTENT_KEY_VERSIONS = [['pestphp/pest-plugin-mutate', '5.0.1', 'r2'], ['pestphp/pest', '5.2.0', 'r1']];

/**
 * A unit's key, from everything it reads as a run has it, or with any of
 * that changed by name.
 *
 * @param list<array{string, string, string}>  $versions each package the runner drives, its version and reference
 * @param array<string, string>                $source   each file outside the tests, by its digest
 * @param array<string, array{string, string}> $cases    each file of test cases, by its digest and source
 * @param array<string, array{string, string}> $others   each other file under the tests, by its digest and source
 * @param list<string>                         $known    the test files the coverage map knows
 * @param list<string>                         $canaries the canary group's test files
 * @param list<string>|CannotJudge             $judges   the test files the runner says can judge the unit
 * @param list<array{int, string}>             $covered  each covered line of the unit with a test covering it
 * @param list<string>                         $analyser the analyser that checks the mutants, its version and its
 *                                                       config's digest; none where empty
 * @param bool                                 $coverageBase whether to answer what every coverage entry's key reads
 */
function contentKeyOf(
    string $gateVersion = '1.0.0',
    string $gateReference = 'abc123',
    string $config = '{"runner":"pëst"}',
    string $runner = 'pest',
    array $versions = CONTENT_KEY_VERSIONS,
    string $platform = 'platform',
    string $installed = 'installed',
    array $source = CONTENT_KEY_SOURCE,
    string $ci = CONTENT_KEY_CI,
    string $template = '',
    array $cases = CONTENT_KEY_CASES,
    array $others = CONTENT_KEY_OTHERS,
    array $known = ['tests/Unit/MoneyTest.php', 'tests/Unit/OtherTest.php', 'tests/Unit/CanaryTest.php'],
    array $canaries = ['tests/Unit/CanaryTest.php'],
    array|CannotJudge $judges = ['tests/Unit/MoneyTest.php', 'tests/Unit/Ghost.php'],
    array $covered = [[3, 'b::t'], [3, 'a::t'], [1, 'c::t']],
    string $unit = 'src/A.php',
    string $group = '',
    string $filter = '',
    array $analyser = [],
    bool $coverageBase = false,
): Digest|Unkeyed {
    $files = [];

    foreach ($cases as $path => $case) {
        $files[] = TestFile::testCase(Fingerprint::of(Path::of($path), Digest::of($case[0])), Contents::of($case[1]));
    }

    foreach ($others as $path => $other) {
        $files[] = TestFile::other(Fingerprint::of(Path::of($path), Digest::of($other[0])), Contents::of($other[1]));
    }

    $outside = Fingerprints::none();

    foreach ($source as $path => $digest) {
        $outside = $outside->with(Fingerprint::of(Path::of($path), Digest::of($digest)));
    }

    $coverage = CoverageMap::empty();

    foreach ($covered as $cover) {
        $coverage = $coverage->covered(Path::of($unit), Line::of($cover[0]), TestId::of($cover[1]));
    }

    $drives = Versions::none();

    foreach ($versions as $version) {
        $drives = $drives->with(Version::of($version[0], $version[1], $version[2]));
    }

    $gate = Version::of('nightworksio/mutation-gate', $gateVersion, $gateReference);
    $identity = Identity::of($runner, $drives, Digest::of($platform));
    $analysed = $analyser === [] ? NoAnalyser::configured() : AnalyserIdentity::of($analyser[0], $analyser[1], Digest::of($analyser[2]));
    $read = Source::of(
        $outside,
        $template === ''
            ? CiDefinitions::of(CiDefinition::at(Path::of('.github/workflows/mutation.yml'), Contents::of($ci)))
            : CiDefinitions::of(
                CiDefinition::at(Path::of('.github/workflows/mutation.yml'), Contents::of($ci)),
                CiDefinition::at(Path::of('.gitlab/template.yml'), Contents::of($template)),
            ),
        Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate-baseline.json'), Ignored::globs('docs/**'), Paths::none()),
    );

    return $coverageBase
        ? ContentKeys::coverageBaseOf($gate, $config, $identity, $analysed, Digest::of($installed), $read)
        : ContentKeys::of(
            $gate,
            $config,
            $identity,
            $analysed,
            Digest::of($installed),
            $read,
            Tests::of(TestFiles::of(...$files), contentKeyPaths($known), contentKeyPaths($canaries)),
        )->keyOf(contentKeyUnit($unit, $group, $filter), contentKeyJudges($judges), $coverage);
}

/**
 * The test files a runner says can judge a unit, or why it cannot say.
 *
 * @param list<string>|CannotJudge $judges
 */
function contentKeyJudges(array|CannotJudge $judges): Paths|CannotJudge
{
    return $judges instanceof CannotJudge ? $judges : contentKeyPaths($judges);
}

/** A unit at a path: held by a group or a filter where one is named, or else the file. */
function contentKeyUnit(string $unit, string $group, string $filter): Unit
{
    return match (true) {
        $group !== '' => Unit::held(Path::of($unit), Group::named($group)),
        $filter !== '' => Unit::held(Path::of($unit), Filter::matching($filter)),
        default => Unit::file(Path::of($unit)),
    };
}

/** @param list<string> $paths */
function contentKeyPaths(array $paths): Paths
{
    $read = Paths::none();

    foreach ($paths as $path) {
        $read = $read->with(Path::of($path));
    }

    return $read;
}

$keyOf = contentKeyOf(...);

/** The SHA-256 of fields, each written with its length in bytes before it. */
$framed = static fn(string ...$fields): Digest => Digest::of(hash('sha256', implode('', array_map(
    static fn(string $field): string => sprintf("%d:%s\n", strlen($field), $field),
    $fields,
))));

it('hashes everything a result could depend on, in order', function () use ($keyOf, $framed): void {
    expect(ContentKeys::FORMAT)->toBe('mutation-gate proof 4')
        ->and($keyOf())->toEqual($framed(
            'mutation-gate proof 4',
            'gate',
            'nightworksio/mutation-gate',
            '1.0.0',
            'abc123',
            'config',
            '{"runner":"pëst"}',
            'runner',
            'pest',
            '2',
            'pestphp/pest',
            '5.2.0',
            'r1',
            'pestphp/pest-plugin-mutate',
            '5.0.1',
            'r2',
            'platform',
            'analyser',
            'none',
            'installed',
            'installed',
            'files',
            '3',
            'composer.json',
            'c1',
            'src/A.php',
            'a1',
            'src/B.php',
            'b1',
            'ci',
            '1',
            '.github/workflows/mutation.yml',
            "name: mutation\nsteps:\n  - uses: actions/checkout@3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d3d # v7\n",
            'always',
            '4',
            'tests/Pest.php',
            'p1',
            'tests/Support/Base.php',
            'b2',
            'tests/Unit/CanaryTest.php',
            'k1',
            'tests/Unit/UnknownTest.php',
            'u1',
            'tests',
            '3',
            'tests/Support/Helper.php',
            'h1',
            'tests/Unit/Ghost.php',
            'missing',
            'tests/Unit/MoneyTest.php',
            'm1',
            'unit',
            'src/A.php',
            'the whole suite',
            '2',
            '1',
            '1',
            'c::t',
            '3',
            '2',
            'a::t',
            'b::t',
        ));
});

it('changes with the analyser that checks the mutants, its version and its config', function (string $analyser, string $version, string $config) use ($keyOf): void {
    expect($keyOf(analyser: [$analyser, $version, $config]))->not->toEqual($keyOf(analyser: ['phpstan', '2.1.30', 'config']));
})->with([
    'another analyser' => ['mago', '2.1.30', 'config'],
    'another version' => ['phpstan', '2.1.31', 'config'],
    'another config' => ['phpstan', '2.1.30', 'config changed'],
]);

it('changes with every input a result could depend on', function (Closure $changed) use ($keyOf): void {
    expect($changed())->not->toEqual($keyOf());
})->with([
    'the gate\'s version' => [fn(): Digest|Unkeyed => $keyOf(gateVersion: '1.0.1')],
    'the gate\'s reference' => [fn(): Digest|Unkeyed => $keyOf(gateReference: 'abc124')],
    'the config' => [fn(): Digest|Unkeyed => $keyOf(config: '{"runner":"infection"}')],
    'the runner' => [fn(): Digest|Unkeyed => $keyOf(runner: 'infection')],
    'a version the runner drives' => [fn(): Digest|Unkeyed => $keyOf(versions: [['pestphp/pest-plugin-mutate', '5.0.1', 'r2'], ['pestphp/pest', '5.2.1', 'r1']])],
    'the platform' => [fn(): Digest|Unkeyed => $keyOf(platform: 'another platform')],
    'a static analyser that checks the mutants' => [fn(): Digest|Unkeyed => $keyOf(analyser: ['phpstan', '2.1.30', 'config'])],
    'what is installed' => [fn(): Digest|Unkeyed => $keyOf(installed: 'installed differently')],
    'a source file' => [fn(): Digest|Unkeyed => $keyOf(source: [...CONTENT_KEY_SOURCE, 'src/A.php' => 'a2'])],
    'the CI definition' => [fn(): Digest|Unkeyed => $keyOf(ci: "name: mutation\n  - run: vendor/bin/pest\n")],
    'the commit an action is pinned at' => [fn(): Digest|Unkeyed => $keyOf(ci: sprintf("name: mutation\n# The gate.\nsteps:\n  - uses: actions/checkout@%s # v7\n", str_repeat('4e', 20)))],
    'a blank line in the CI definition' => [fn(): Digest|Unkeyed => $keyOf(ci: sprintf("name: mutation\n\nsteps:\n  - uses: actions/checkout@%s # v7\n", str_repeat('3d', 20)))],
    'a second CI definition that runs the gate' => [fn(): Digest|Unkeyed => $keyOf(template: 'stages: [mutation]')],
    'a judging test' => [fn(): Digest|Unkeyed => $keyOf(cases: [...CONTENT_KEY_CASES, 'tests/Unit/MoneyTest.php' => ['m2', "<?php\nit('adds', fn() => new Helper());\n"]])],
    'the support a judging test names' => [fn(): Digest|Unkeyed => $keyOf(others: [...CONTENT_KEY_OTHERS, 'tests/Support/Helper.php' => ['h2', "<?php\nfinal class Helper {}\n"]])],
    'a file that runs when it is loaded' => [fn(): Digest|Unkeyed => $keyOf(others: [...CONTENT_KEY_OTHERS, 'tests/Pest.php' => ['p2', "<?php\nuses(Base::class);\n"]])],
    'the support such a file names' => [fn(): Digest|Unkeyed => $keyOf(others: [...CONTENT_KEY_OTHERS, 'tests/Support/Base.php' => ['b3', "<?php\nabstract class Base {}\n"]])],
    'a test file the coverage map does not know' => [fn(): Digest|Unkeyed => $keyOf(cases: [...CONTENT_KEY_CASES, 'tests/Unit/UnknownTest.php' => ['u2', "<?php\nit('works');\n"]])],
    'a canary' => [fn(): Digest|Unkeyed => $keyOf(cases: [...CONTENT_KEY_CASES, 'tests/Unit/CanaryTest.php' => ['k2', "<?php\nit('sings');\n"]])],
    'the tests the runner says can judge the unit' => [fn(): Digest|Unkeyed => $keyOf(judges: ['tests/Unit/MoneyTest.php'])],
    'a test covering a line' => [fn(): Digest|Unkeyed => $keyOf(covered: [[3, 'b::t'], [3, 'a::t'], [1, 'c::t'], [1, 'd::t']])],
    'the lines covered' => [fn(): Digest|Unkeyed => $keyOf(covered: [[3, 'b::t'], [3, 'a::t'], [2, 'c::t']])],
    'the unit' => [fn(): Digest|Unkeyed => $keyOf(unit: 'src/B.php')],
    'a group holding the unit' => [fn(): Digest|Unkeyed => $keyOf(group: 'holds')],
    'a filter holding the unit' => [fn(): Digest|Unkeyed => $keyOf(filter: 'holds')],
]);

it('stays the same for what no result depends on', function (Closure $unchanged) use ($keyOf): void {
    expect($unchanged())->toEqual($keyOf());
})->with([
    'the config file' => [fn(): Digest|Unkeyed => $keyOf(source: [...CONTENT_KEY_SOURCE, 'mutation-gate.json' => 'g2'])],
    'the baseline' => [fn(): Digest|Unkeyed => $keyOf(source: [...CONTENT_KEY_SOURCE, 'mutation-gate-baseline.json' => 'l2'])],
    'what proofs.ignore matches' => [fn(): Digest|Unkeyed => $keyOf(source: [...CONTENT_KEY_SOURCE, 'docs/index.md' => 'd2'])],
    'another CI definition' => [fn(): Digest|Unkeyed => $keyOf(source: [...CONTENT_KEY_SOURCE, '.github/workflows/ci.yml' => 'w2'])],
    'the ledger' => [fn(): Digest|Unkeyed => $keyOf(source: [...CONTENT_KEY_SOURCE, '.mutation-gate/ledger/refs/heads/main/ledger.json' => 'l2'])],
    'the CI definition\'s own digest' => [fn(): Digest|Unkeyed => $keyOf(source: [...CONTENT_KEY_SOURCE, '.github/workflows/mutation.yml' => 'm2'])],
    'a comment line in the CI definition' => [fn(): Digest|Unkeyed => $keyOf(ci: sprintf("name: mutation\n# Another comment.\nsteps:\n  # And one more.\n  - uses: actions/checkout@%s # v7\n", str_repeat('3d', 20)))],
    'a test the map knows and that cannot judge the unit' => [fn(): Digest|Unkeyed => $keyOf(cases: [...CONTENT_KEY_CASES, 'tests/Unit/OtherTest.php' => ['o2', "<?php\nit('other', fn() => new Unused());\n"]])],
    'support nothing names' => [fn(): Digest|Unkeyed => $keyOf(others: [...CONTENT_KEY_OTHERS, 'tests/Support/Unused.php' => ['x2', "<?php\nfinal class Unused {}\n"]])],
    'the order the runner lists the packages it drives in' => [fn(): Digest|Unkeyed => $keyOf(versions: array_reverse(CONTENT_KEY_VERSIONS))],
    'the order the files are listed in' => [fn(): Digest|Unkeyed => $keyOf(source: array_reverse(CONTENT_KEY_SOURCE))],
]);

it('reads no canary where there is none', function () use ($keyOf): void {
    $cases = [...CONTENT_KEY_CASES, 'tests/Unit/CanaryTest.php' => ['k2', "<?php\nit('sings');\n"]];

    expect($keyOf(canaries: [], cases: $cases))->toEqual($keyOf(canaries: []));
});

it('reads every test case for a held unit, whatever the runner says', function () use ($keyOf): void {
    $other = [...CONTENT_KEY_CASES, 'tests/Unit/OtherTest.php' => ['o2', "<?php\nit('other', fn() => new Unused());\n"]];

    expect($keyOf(group: 'holds', judges: []))->toEqual($keyOf(group: 'holds'))
        ->and($keyOf(group: 'holds', cases: $other))->not->toEqual($keyOf(group: 'holds'));
});

it('writes what holds a unit into its key', function () use ($keyOf): void {
    expect($keyOf(group: 'holds'))->not->toEqual($keyOf(filter: 'holds'));
});

it('keys no unit whose judging tests the runner cannot name', function () use ($keyOf): void {
    expect($keyOf(judges: CannotJudge::because('A covering test does not fit the filter.')))
        ->toEqual(Unkeyed::because('A covering test does not fit the filter.'));
});

$bare = static fn(): ContentKeys => ContentKeys::of(
    Version::of('nightworksio/mutation-gate', '1.0.0', 'abc123'),
    '{}',
    Identity::of('pest', Versions::none(), Digest::of('platform')),
    NoAnalyser::configured(),
    Digest::of('installed'),
    Source::of(Fingerprints::none(), CiDefinitions::none(), Exceptions::of(Path::of('a'), Path::of('b'), Ignored::nothing(), Paths::none())),
    Tests::of(TestFiles::of(), Paths::none(), Paths::none()),
);

it('writes what holds a unit, by its name', function (Unit $unit, string $heldBy) use ($bare, $framed): void {
    expect($bare()->keyOf($unit, Paths::none(), CoverageMap::empty()))->toEqual($framed(
        'mutation-gate proof 4',
        'gate',
        'nightworksio/mutation-gate',
        '1.0.0',
        'abc123',
        'config',
        '{}',
        'runner',
        'pest',
        '0',
        'platform',
        'analyser',
        'none',
        'installed',
        'installed',
        'files',
        '0',
        'ci',
        '0',
        'always',
        '0',
        'tests',
        '0',
        'unit',
        'src/A.php',
        $heldBy,
        '0',
    ));
})->with([
    'the whole suite' => [Unit::file(Path::of('src/A.php')), 'the whole suite'],
    'a group' => [Unit::held(Path::of('src/A.php'), Group::named('holds')), 'the group holds'],
    'a filter' => [Unit::held(Path::of('src/A.php'), Filter::matching('Money')), 'the filter Money'],
]);

it('keys one unit after another alike', function () use ($bare): void {
    $keys = $bare();
    $first = $keys->keyOf(Unit::file(Path::of('src/A.php')), Paths::none(), CoverageMap::empty());
    $keys->keyOf(Unit::file(Path::of('src/B.php')), Paths::none(), CoverageMap::empty());

    expect($keys->keyOf(Unit::file(Path::of('src/A.php')), Paths::none(), CoverageMap::empty()))->toEqual($first);
});

/** Content keys of a project with support that names support, paths of digits and a unit held each way. */
function contentKeysPinned(): ContentKeys
{
    $fingerprint = static fn(string $path, string $digest): Fingerprint => Fingerprint::of(Path::of($path), Digest::of($digest));
    $paths = static fn(string ...$paths): Paths => Paths::of(...array_map(Path::of(...), $paths));

    return ContentKeys::of(
        Version::of('nightworksio/mutation-gate', '1.2.3', 'abc'),
        '{"runner":"pest","floor":80}',
        Identity::of('pest', Versions::of(Version::of('pestphp/pest', '4.1.0', 'r1'), Version::of('pestphp/pest-plugin-mutate', '4.0.1', 'r2')), Digest::of('platform')),
        NoAnalyser::configured(),
        Digest::of('installed'),
        Source::of(
            Fingerprints::of($fingerprint('src/B.php', 'b'), $fingerprint('src/A.php', 'a'), $fingerprint('10', 'ten'), $fingerprint('9', 'nine'), $fingerprint('composer.json', 'c')),
            CiDefinitions::of(
                CiDefinition::at(Path::of('.github/workflows/gate.yml'), Contents::of("name: gate\n")),
                CiDefinition::at(Path::of('.gitlab-ci.yml'), Contents::of("stages: [gate]\n")),
            ),
            Exceptions::of(Path::of('mutation-gate.json'), Path::of('baseline.json'), Ignored::nothing(), Paths::none()),
        ),
        Tests::of(
            TestFiles::of(
                TestFile::testCase($fingerprint('tests/Unit/MoneyTest.php', 'm1'), Contents::of("<?php\nuse Tests\\Support\\Helper;\nit('adds', fn() => new Helper());\n")),
                TestFile::testCase($fingerprint('tests/Unit/LedgerTest.php', 'l1'), Contents::of("<?php\nit('books', fn() => new Wing());\n")),
                TestFile::testCase($fingerprint('tests/Unit/UnknownTest.php', 'u1'), Contents::of("<?php\nit('works');\n")),
                TestFile::testCase($fingerprint('tests/Unit/CanaryTest.php', 'k1'), Contents::of("<?php\nit('sings', fn() => new Bird());\n")),
                TestFile::testCase($fingerprint('tests/10', 't10'), Contents::of("<?php\nit('ten', fn() => new Helper());\n")),
                TestFile::testCase($fingerprint('tests/9', 't9'), Contents::of("<?php\nit('nine');\n")),
                TestFile::other($fingerprint('tests/Pest.php', 'p1'), Contents::of("<?php\nuses(Base::class);\n")),
                TestFile::other($fingerprint('tests/fixtures/money.json', 'j1'), Contents::of('{"amount": 1}')),
                TestFile::other($fingerprint('tests/Support/Helper.php', 'h1'), Contents::of("<?php\nnamespace Tests\\Support;\nfinal class Helper { public function a(): Wing { return new Wing(); } }\n")),
                TestFile::other($fingerprint('tests/Support/Wing.php', 'w1'), Contents::of("<?php\nfinal class Wing { public function b(): Helper { return new Helper(); } }\n")),
                TestFile::other($fingerprint('tests/Support/Base.php', 'b1'), Contents::of("<?php\nabstract class Base {}\n")),
                TestFile::other($fingerprint('tests/Support/Bird.php', 'r1'), Contents::of("<?php\nfinal class Bird {}\n")),
                TestFile::other($fingerprint('tests/Support/Unused.php', 'x1'), Contents::of("<?php\nfinal class Unused {}\n")),
            ),
            $paths('tests/Unit/MoneyTest.php', 'tests/Unit/LedgerTest.php', 'tests/Unit/CanaryTest.php', 'tests/10', 'tests/9'),
            $paths('tests/Unit/CanaryTest.php'),
        ),
    );
}

it('keys each unit of a fixture with the bytes its format gives it, whatever order its judges come in', function (Unit $unit, Paths $judges, string $key): void {
    $coverage = CoverageMap::empty();

    foreach ([[3, 'b::t'], [3, 'a::t'], [1, 'c::t'], [3, '10'], [3, '9'], [7, 'a::t']] as [$line, $test]) {
        $coverage = $coverage->covered(Path::of('src/A.php'), Line::of($line), TestId::of($test));
    }

    $coverage = $coverage->covered(Path::of('src/B.php'), Line::of(2), TestId::of('b::t'));
    $keys = contentKeysPinned();

    expect($keys->keyOf($unit, $judges, $coverage))->toEqual(Digest::of($key))
        ->and($keys->keyOf($unit, Paths::of(...array_reverse([...$judges])), $coverage))->toEqual(Digest::of($key));
})->with([
    'a unit judged by one test file' => [Unit::file(Path::of('src/A.php')), Paths::of(Path::of('tests/Unit/MoneyTest.php')), '2e1336c4087c23c7cf3d9a0cff6aef712260018272ca66d597084c3a5abf6cef'],
    'a unit judged by test files named by digits' => [Unit::file(Path::of('src/A.php')), Paths::of(Path::of('tests/10'), Path::of('tests/9'), Path::of('tests/Unit/LedgerTest.php')), 'ec90d582aad95f1ac6677b28cf795ffae2e71adf877773425342cc588ce8743e'],
    'a unit judged by a test file the key does not hold' => [Unit::file(Path::of('src/B.php')), Paths::of(Path::of('tests/Unit/GoneTest.php'), Path::of('tests/Unit/MoneyTest.php')), 'aaa4d928c769fc5ce16082d1c69f2c5b2202924cda849e098afa789d5c88ab07'],
    'a unit nothing judges' => [Unit::file(Path::of('src/C.php')), Paths::none(), 'ed2a0e79b4c3048e83ec5ba4c18e58b7dbe19bdc50f1b7b5078e48fe569f384e'],
    'a unit a group holds' => [Unit::held(Path::of('src/A.php'), Group::named('holds:src/A.php')), Paths::none(), 'd6837f6de13d65979fa49b9f773161a3e27c5e4d6648f787685060d3441b2761'],
    'a unit a filter holds' => [Unit::held(Path::of('src/B.php'), Filter::matching('Money')), Paths::of(Path::of('tests/Unit/MoneyTest.php')), '61e42728a6fe0532c4dc36fb3d5e508013e4c958f5e23e12c9e77ec76e29d804'],
]);

it('keys many units at once as it keys each alone, reading a set of judging files once', function (): void {
    $coverage = CoverageMap::empty()
        ->covered(Path::of('src/A.php'), Line::of(3), TestId::of('b::t'))
        ->covered(Path::of('src/B.php'), Line::of(2), TestId::of('a::t'));
    $paths = static fn(string ...$paths): Paths => Paths::of(...array_map(Path::of(...), $paths));
    $units = [
        Judging::of(Unit::file(Path::of('src/A.php')), $paths('tests/Unit/MoneyTest.php', 'tests/10')),
        Judging::of(Unit::file(Path::of('src/B.php')), $paths('tests/10', 'tests/Unit/MoneyTest.php')),
        Judging::of(Unit::file(Path::of('src/C.php')), CannotJudge::because('A covering test does not fit the filter.')),
        Judging::of(Unit::held(Path::of('src/D.php'), Group::named('holds:src/D.php')), $paths('tests/9')),
        Judging::of(Unit::file(Path::of('src/E.php')), $paths('tests/9')),
    ];
    $keys = contentKeysPinned();
    $alone = Keys::none();

    foreach ($units as $unit) {
        $alone = $alone->with($unit->unit()->path(), $keys->keyOf($unit->unit(), $unit->judges(), $coverage));
    }

    expect($keys->keysOf($coverage, ...$units))->toEqual($alone)
        ->and($keys->keysOf($coverage)->units())->toEqual(Paths::none())
        ->and($alone->keyOf(Path::of('src/C.php')))->toEqual(Unkeyed::because('A covering test does not fit the filter.'))
        ->and($alone->keyOf(Path::of('src/D.php')))->not->toEqual($alone->keyOf(Path::of('src/E.php')));
});

it('keys units in time linear in the test files that judge them', function (): void {
    $keying = static function (int $size): array {
        $files = [TestFile::other(Fingerprint::of(Path::of('tests/Support/Helper.php'), Digest::of('h')), Contents::of("<?php\nfinal class Helper {}\n"))];
        $judges = [];

        foreach (range(1, $size) as $at) {
            $judges[] = Path::of(sprintf('tests/Unit/T%dTest.php', $at));
            $files[] = TestFile::testCase(Fingerprint::of(end($judges), Digest::of(sprintf('%d', $at))), Contents::of("<?php\nit('works', fn() => new Helper());\n"));
        }

        $keys = ContentKeys::of(
            Version::of('nightworksio/mutation-gate', '1.0.0', 'abc123'),
            '{}',
            Identity::of('pest', Versions::none(), Digest::of('platform')),
            NoAnalyser::configured(),
            Digest::of('installed'),
            Source::of(Fingerprints::none(), CiDefinitions::none(), Exceptions::of(Path::of('a'), Path::of('b'), Ignored::nothing(), Paths::none())),
            Tests::of(TestFiles::of(...$files), Paths::of(...$judges), Paths::none()),
        );
        $units = array_map(
            static fn(int $at): Judging => Judging::of(Unit::file(Path::of(sprintf('src/F%d.php', $at))), Paths::of(...($at % 2 === 0 ? $judges : array_reverse($judges)))),
            range(1, 50),
        );

        return [$keys, $judges, static fn(): Keys => $keys->keysOf(CoverageMap::empty(), ...$units)];
    };
    [$keys, $judges, $keyed] = $keying(10);

    expect($keyed())->toHaveCount(50)
        ->and($keyed()->keyOf(Path::of('src/F1.php')))->toEqual($keys->keyOf(Unit::file(Path::of('src/F1.php')), Paths::of(...$judges), CoverageMap::empty()))
        ->and(Growth::of(250, static fn(int $size): Closure => $keying($size)[2]))->toBeLessThan(Growth::LINEAR);
});

it('names the base every key of a run is built on: the digest of what every key reads', function () use ($bare, $framed): void {
    $keys = $bare();

    expect($keys->base())->toEqual($framed(
        'mutation-gate proof 4',
        'gate',
        'nightworksio/mutation-gate',
        '1.0.0',
        'abc123',
        'config',
        '{}',
        'runner',
        'pest',
        '0',
        'platform',
        'analyser',
        'none',
        'installed',
        'installed',
        'files',
        '0',
        'ci',
        '0',
        'always',
        '0',
    ))
        ->and($keys->keyOf(Unit::file(Path::of('src/A.php')), Paths::none(), CoverageMap::empty()))
        ->toEqual($bare()->keyOf(Unit::file(Path::of('src/A.php')), Paths::none(), CoverageMap::empty()));
});

/**
 * The digests of a run's inputs, as keys built from this config and these
 * files outside the tests give them, of these units.
 *
 * @param array<string, string> $source each file outside the tests, by its digest
 */
function contentDigestsOf(
    Units $units,
    string $config = '{"runner":"pëst"}',
    array $source = [...CONTENT_KEY_SOURCE, 'phpunit.xml' => 'x9'],
): Digests {
    $files = [];

    foreach (CONTENT_KEY_CASES as $path => $case) {
        $files[] = TestFile::testCase(Fingerprint::of(Path::of($path), Digest::of($case[0])), Contents::of($case[1]));
    }

    foreach (CONTENT_KEY_OTHERS as $path => $other) {
        $files[] = TestFile::other(Fingerprint::of(Path::of($path), Digest::of($other[0])), Contents::of($other[1]));
    }

    $outside = Fingerprints::none();

    foreach ($source as $path => $digest) {
        $outside = $outside->with(Fingerprint::of(Path::of($path), Digest::of($digest)));
    }

    return ContentKeys::of(
        Version::of('nightworksio/mutation-gate', '1.0.0', 'abc123'),
        $config,
        Identity::of('pest', Versions::none(), Digest::of('platform')),
        NoAnalyser::configured(),
        Digest::of('installed'),
        Source::of(
            $outside,
            CiDefinitions::none(),
            Exceptions::of(
                Path::of('mutation-gate.json'),
                Path::of('mutation-gate-baseline.json'),
                Ignored::globs('docs/**'),
                Paths::of(Path::of('phpunit.xml')),
            ),
        ),
        Tests::of(TestFiles::of(...$files), contentKeyPaths(['tests/Unit/MoneyTest.php', 'tests/Unit/OtherTest.php']), Paths::none()),
    )->digestsOf($units);
}

it('digests what decides a mutant set, each unit\'s source, and each test file with the support it reads', function () use ($framed): void {
    $digests = contentDigestsOf(Units::of(Unit::file(Path::of('src/A.php')), Unit::held(Path::of('src'), Group::named('holds:src'))));

    expect($digests->mutation())->toEqual($framed(
        'mutation-gate proof 4',
        'gate',
        'nightworksio/mutation-gate',
        '1.0.0',
        'abc123',
        'config',
        '{"runner":"pëst"}',
        'runner',
        'pest',
        '0',
        'platform',
        'analyser',
        'none',
        'installed',
        'installed',
        'definitions',
        '1',
        'phpunit.xml',
        'x9',
        'always',
        '4',
        'tests/Pest.php',
        'p1',
        'tests/Support/Base.php',
        'b2',
        'tests/Unit/CanaryTest.php',
        'k1',
        'tests/Unit/UnknownTest.php',
        'u1',
    ))
        ->and($digests->sourceOf(Path::of('src/A.php')))->toEqual($framed('source', 'src/A.php', '1', 'src/A.php', 'a1'))
        ->and($digests->sourceOf(Path::of('src')))->toEqual($framed('source', 'src', '2', 'src/A.php', 'a1', 'src/B.php', 'b1'))
        ->and($digests->testOf(Path::of('tests/Unit/MoneyTest.php')))
        ->toEqual($framed('tests', '2', 'tests/Support/Helper.php', 'h1', 'tests/Unit/MoneyTest.php', 'm1'))
        ->and($digests->testOf(Path::of('tests/Unit/OtherTest.php')))
        ->toEqual($framed('tests', '2', 'tests/Support/Unused.php', 'x1', 'tests/Unit/OtherTest.php', 'o1'))
        ->and($digests->tests()->paths())->toEqual(contentKeyPaths(array_keys(CONTENT_KEY_CASES)));
});

it('digests a unit\'s own source even where every key leaves its file out', function () use ($framed): void {
    expect(contentDigestsOf(Units::of(Unit::file(Path::of('docs/index.md'))))->sourceOf(Path::of('docs/index.md')))
        ->toEqual($framed('source', 'docs/index.md', '1', 'docs/index.md', 'd1'));
});

it('keeps what decides a mutant set when a source changes, and changes it with the config or the runner\'s definitions', function (): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')));
    $digests = contentDigestsOf($units);
    $edited = contentDigestsOf($units, source: [...CONTENT_KEY_SOURCE, 'phpunit.xml' => 'x9', 'src/B.php' => 'b2']);

    expect($edited->mutation())->toEqual($digests->mutation())
        ->and($edited->sourceOf(Path::of('src/A.php')))->toEqual($digests->sourceOf(Path::of('src/A.php')))
        ->and(contentDigestsOf($units, '{"runner":"infection"}')->mutation())->not->toEqual($digests->mutation())
        ->and(contentDigestsOf($units, source: [...CONTENT_KEY_SOURCE, 'phpunit.xml' => 'x8'])->mutation())
        ->not->toEqual($digests->mutation());
});

it('keys every coverage entry on what each reads, and leaves a PHP source and the tests to the entries that read them', function (): void {
    $source = static fn(string $path, string $digest): array => [...CONTENT_KEY_SOURCE, $path => $digest];
    $base = contentKeyOf(coverageBase: true);

    expect(contentKeyOf(source: $source('src/A.php', 'a2'), coverageBase: true))->toEqual($base)
        ->and(contentKeyOf(source: $source('src/New.php', 'n1'), coverageBase: true))->toEqual($base)
        ->and(contentKeyOf(source: $source('docs/index.md', 'd2'), coverageBase: true))->toEqual($base)
        ->and(contentKeyOf(source: $source('mutation-gate.json', 'g2'), coverageBase: true))->toEqual($base)
        ->and(contentKeyOf(cases: [...CONTENT_KEY_CASES, 'tests/Unit/MoneyTest.php' => ['m2', '<?php']], coverageBase: true))->toEqual($base)
        ->and(contentKeyOf(known: [], canaries: [], judges: [], covered: [], coverageBase: true))->toEqual($base)
        ->and(contentKeyOf(source: $source('composer.json', 'c2'), coverageBase: true))->not->toEqual($base)
        ->and(contentKeyOf(source: $source('templates/mail.twig', 't1'), coverageBase: true))->not->toEqual($base)
        ->and(contentKeyOf(config: '{"runner":"infection"}', coverageBase: true))->not->toEqual($base)
        ->and(contentKeyOf(ci: "name: other\n", coverageBase: true))->not->toEqual($base)
        ->and(contentKeyOf(installed: 'other', coverageBase: true))->not->toEqual($base)
        ->and(contentKeyOf(gateVersion: '1.0.1', coverageBase: true))->not->toEqual($base)
        ->and(contentKeyOf())->not->toEqual($base);
});
