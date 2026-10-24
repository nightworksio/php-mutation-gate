<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Proof\Key\Source;
use NightWorksIO\MutationGate\Core\Proof\Key\SourceReach;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Proof\Key\Tests;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Unit\Unit;

// A project of five sources: src/Money.php, which names src/Currency.php,
// and src/Rate.php, which src/Money.php's test executes beside it; and
// src/Tax.php and src/Ledger.php, which only src/Tax.php's test executes.
// src/Boot.php is PHP no test executes, and composer.json, phpunit.xml and
// the test fixture tests/fixtures/rates.json are no PHP.

/** @var array<string, string> each file of the project outside its tests, with its digest */
const REACH_FILES = [
    'src/Money.php' => 'money',
    'src/Currency.php' => 'currency',
    'src/Rate.php' => 'rate',
    'src/Tax.php' => 'tax',
    'src/Ledger.php' => 'ledger',
    'src/Boot.php' => 'boot',
    'composer.json' => 'composer',
    'phpunit.xml' => 'phpunit',
];

/** What the project's tests ran: Money's test Money and Rate, and Tax's test Tax and Ledger. */
function reachMap(): CoverageMap
{
    return CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Rate.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Tax.php'), Line::of(4), TestId::of('TaxTest::levies'))
        ->covered(Path::of('src/Ledger.php'), Line::of(2), TestId::of('TaxTest::levies'));
}

/** The project's name graph: src/Money.php names src/Currency.php, which it never runs. */
function reachNames(): NamedFiles
{
    return NamedFiles::byName(
        ['src/Money.php' => ['Money'], 'src/Currency.php' => ['Currency'], 'src/Rate.php' => ['Rate'], 'src/Tax.php' => ['Tax'], 'src/Ledger.php' => ['Ledger']],
        ['src/Money.php' => ['Currency'], 'src/Tax.php' => [], 'tests/MoneyTest.php' => ['Money'], 'tests/TaxTest.php' => ['Tax']],
    );
}

/**
 * The project's keys, with these files' digests in place of theirs, a fixture of this digest and the PHP on this
 * digest, read unit by unit through the project's graph where it was read.
 *
 * @param array<string, string> $changed
 */
function reachKeys(array $changed = [], string $fixture = 'rates', string $platform = 'php-8.5', bool $graphRead = true): ContentKeys
{
    $files = array_map(
        static fn(string $path, string $digest): Fingerprint => Fingerprint::of(Path::of($path), Digest::of($digest)),
        array_keys([...REACH_FILES, ...$changed]),
        [...REACH_FILES, ...$changed],
    );
    $test = static fn(string $path, string $code): TestFile => TestFile::testCase(Fingerprint::of(Path::of($path), Digest::of($path)), Contents::of($code));

    return ContentKeys::of(
        Version::of('nightworksio/mutation-gate', '1.0.0', 'abc'),
        '{}',
        Identity::of('phpunit', Versions::none(), Digest::of($platform)),
        NoAnalyser::configured(),
        Digest::of('installed'),
        Source::of(Fingerprints::of(...$files), CiDefinitions::none(), Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate.baseline.json'), Ignored::nothing(), Paths::of(Path::of('phpunit.xml')))),
        Tests::of(
            TestFiles::of(
                $test('tests/MoneyTest.php', "<?php\nfinal class MoneyTest { public function adds() { new Money(); } }\n"),
                $test('tests/TaxTest.php', "<?php\nfinal class TaxTest { public function levies() { new Tax(); } }\n"),
                TestFile::other(Fingerprint::of(Path::of('tests/fixtures/rates.json'), Digest::of($fixture)), Contents::of('{"rate": 1}')),
            ),
            Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/TaxTest.php')),
            Paths::none(),
        ),
        $graphRead ? SourceReach::of(reachNames(), reachMap()) : SourceReach::nothing(),
    );
}

/** Money's key, judged by its test, from these keys. */
function moneyKey(ContentKeys $keys): Digest|Unkeyed
{
    return $keys->keyOf(Unit::file(Path::of('src/Money.php')), Paths::of(Path::of('tests/MoneyTest.php')), reachMap());
}

it('keeps a unit\'s key where a file its tests neither execute nor name changes', function (string $file): void {
    expect(moneyKey(reachKeys([$file => 'changed'])))->toEqual(moneyKey(reachKeys()));
})->with([
    'a source only another unit\'s test executes' => ['src/Tax.php'],
    'a source that executes only under another unit\'s test' => ['src/Ledger.php'],
]);

it('moves a unit\'s key where a file its tests execute, or one they or it name, changes', function (string $file): void {
    expect(moneyKey(reachKeys([$file => 'changed'])))->not->toEqual(moneyKey(reachKeys()));
})->with([
    'its own file' => ['src/Money.php'],
    'a source its test executes beside it' => ['src/Rate.php'],
    'a source it names and never runs' => ['src/Currency.php'],
]);

it('moves every key where a file no map shows run, the PHP, or a fixture of the tests changes', function (ContentKeys $changed): void {
    $taxKey = static fn(ContentKeys $keys): Digest|Unkeyed => $keys->keyOf(Unit::file(Path::of('src/Tax.php')), Paths::of(Path::of('tests/TaxTest.php')), reachMap());

    expect(moneyKey($changed))->not->toEqual(moneyKey(reachKeys()))
        ->and($taxKey($changed))->not->toEqual($taxKey(reachKeys()));
})->with([
    'composer.json' => [fn(): ContentKeys => reachKeys(['composer.json' => 'changed'])],
    'the PHPUnit config, which defines the runner' => [fn(): ContentKeys => reachKeys(['phpunit.xml' => 'changed'])],
    'PHP no test executes' => [fn(): ContentKeys => reachKeys(['src/Boot.php' => 'changed'])],
    'a new file' => [fn(): ContentKeys => reachKeys(['src/New.php' => 'new'])],
    'the PHP the runner runs on, its extensions among it' => [fn(): ContentKeys => reachKeys(platform: 'php-8.5-with-intl')],
    'a fixture of the tests' => [fn(): ContentKeys => reachKeys(fixture: 'changed')],
]);

it('moves a held unit\'s key where any file a map shows run changes, as any test can join its group', function (): void {
    $held = static fn(ContentKeys $keys): Digest|Unkeyed => $keys->keyOf(Unit::held(Path::of('src/Money.php'), Group::named('holds:src/Money.php')), Paths::none(), reachMap());

    expect($held(reachKeys(['src/Ledger.php' => 'changed'])))->not->toEqual($held(reachKeys()));
});

it('moves every key where any file outside the tests changes, where no name graph was read', function (): void {
    expect(moneyKey(reachKeys(['src/Ledger.php' => 'changed'], graphRead: false)))->not->toEqual(moneyKey(reachKeys(graphRead: false)));
});

it('reads every file outside the tests in the base but those a map shows run, and those that define the runner whatever it shows', function (): void {
    $files = Fingerprints::of(...array_map(static fn(string $path): Fingerprint => Fingerprint::of(Path::of($path), Digest::of($path)), ['src/Money.php', 'src/Boot.php', 'composer.json', 'phpunit.xml']));
    $source = Source::of($files, CiDefinitions::none(), Exceptions::of(Path::of('mutation-gate.json'), Path::of('b.json'), Ignored::nothing(), Paths::of(Path::of('phpunit.xml'))));
    $observed = reachMap()->covered(Path::of('phpunit.xml'), Line::of(1), TestId::of('MoneyTest::adds'));
    $guarded = array_map(static fn(Fingerprint $file): string => $file->path()->value(), [...SourceReach::of(reachNames(), $observed)->guarded($source)]);

    expect($guarded)->toBe(['src/Boot.php', 'composer.json', 'phpunit.xml'])
        ->and(count(SourceReach::nothing()->guarded($source)))->toBe(4);
});
