<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\AffectedCommand;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** The commit the map was measured at. */
const AFFECTED_AT = '0123456789abcdef0123456789abcdef01234567';

/**
 * `affected` run with these options through this runner, in the fixture's
 * project with a map measured at its commit, where these changed since it.
 */
$affected = static function (string $options, ScriptedRunner $runner, Change ...$changes): FlowCommands {
    $project = FlowCommands::project();
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'));
    Scratch::write($project, '.mutation-gate/coverage/map.json.gz', CoverageMapFile::encode(
        $map,
        MeasuredAt::of(Revision::ref(AFFECTED_AT), dirty: false),
    ));
    $checkout = new ChangeSourceFake(
        Revision::ref(AFFECTED_AT),
        Changes::of(...$changes),
        [Revision::workingTree()->name() => Flows::FILES, AFFECTED_AT => Flows::FILES],
    );

    return FlowCommands::run(AffectedCommand::command(FlowCommands::reading(
        Flows::trees(),
        $project,
        $runner,
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
        RepositoryFake::onMain(Revision::ref(Flows::HEAD)),
        $checkout,
    )), $options);
};

$makeMoney = static fn(): Change => Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(11)));

$makeTest = static fn(): Change => Change::modified(Path::of('tests/MoneyTest.php'), Lines::of(Line::of(3)));

$phpunit = static fn(string $version): Version => Version::of('phpunit/phpunit', $version, 'abc');

it('prints the test files a change reaches a line each, and each reason on standard error', function () use ($affected, $makeMoney): void {
    $money = $makeMoney();

    $printed = $affected('', ScriptedRunner::fixture(), $money);

    expect([$printed->code, $printed->output, $printed->errors])->toBe([
        0,
        "tests/DrainSpec.php\ntests/MoneySpec.php\n",
        "tests/DrainSpec.php: `src/Money.php` changed, and the runner selects this file to judge it.\n"
            . "tests/MoneySpec.php: `src/Money.php` changed, and the runner selects this file to judge it.\n",
    ]);
});

it('ends each file with a NUL byte for files0', function () use ($affected, $makeMoney): void {
    $money = $makeMoney();

    expect($affected('--format=files0', ScriptedRunner::fixture(), $money)->output)->toBe("tests/DrainSpec.php\0tests/MoneySpec.php\0");
});

it('prints the whole answer as JSON, with nothing on standard error', function () use ($affected, $makeTest): void {
    $test = $makeTest();

    $printed = $affected('--format=json', ScriptedRunner::fixture(), $test);
    $json = $printed->output;

    expect([$printed->code, $printed->errors, Decoded::at($json, 'base'), Decoded::at($json, 'all'), Decoded::at($json, 'tests')])->toBe([0, '', AFFECTED_AT, false, [[
        'file' => 'tests/MoneyTest.php',
        'ids' => ['MoneyTest::adds'],
        'reasons' => ['`tests/MoneyTest.php` changed.'],
    ]]]);
});

it('prints nothing, and exits 0, where the change reaches no test', function () use ($affected): void {
    $printed = $affected('', ScriptedRunner::fixture());

    expect([$printed->code, $printed->output, $printed->errors])->toBe([0, '', '']);
});

it('prints the test ids a change reaches where the runner drives a PHPUnit that takes them', function () use (
    $affected,
    $makeTest,
    $phpunit,
): void {
    $test = $makeTest();

    $printed = $affected('--format=ids', ScriptedRunner::fixture()->driving($phpunit('13.2.0')), $test);

    expect([$printed->code, $printed->output])->toBe([0, "MoneyTest::adds\n"]);
});

it('refuses ids, naming files, where the runner\'s ids are not ones PHPUnit\'s filter takes', function (
    ScriptedRunner $runner,
    string $why,
) use ($affected, $makeTest): void {
    $test = $makeTest();

    $printed = $affected('--format=ids', $runner, $test);

    expect([$printed->code, $printed->output, $printed->errors])->toBe([2, '', sprintf("%s\n", $why)]);
})->with([
    'Pest' => [
        fn(): ScriptedRunner => ScriptedRunner::fixture()->driving(Version::of('pestphp/pest', '5.2.1', 'abc'), Version::of('phpunit/phpunit', '13.3.4', 'abc')),
        'Pest\'s test ids are not ones PHPUnit\'s filter takes: give --format=files.',
    ],
    'a PHPUnit before 13.2' => [
        fn(): ScriptedRunner => ScriptedRunner::fixture()->driving(Version::of('phpunit/phpunit', '13.1.6', 'abc')),
        '--format=ids needs PHPUnit 13.2.0 or later, and the runner drives 13.1.6: give --format=files.',
    ],
    'no PHPUnit' => [
        fn(): ScriptedRunner => ScriptedRunner::fixture()->driving(Version::of('fake/runner', '1.0.0', 'abc')),
        '--format=ids needs PHPUnit 13.2.0 or later, and the runner drives no PHPUnit: give --format=files.',
    ],
]);

it('refuses ids where a file listed holds no test the map names', function () use ($affected, $makeMoney, $phpunit): void {
    $money = $makeMoney();

    $printed = $affected('--format=ids', ScriptedRunner::fixture()->driving($phpunit('13.3.4')), $money);

    expect([$printed->code, $printed->errors])->toBe([
        2,
        "`tests/DrainSpec.php` holds no test the coverage map names, so --format=ids cannot select it: give --format=files.\n",
    ]);
});

it('refuses a format it does not know', function () use ($affected): void {
    $printed = $affected('--format=csv', ScriptedRunner::fixture());

    expect([$printed->code, $printed->errors])->toBe([2, "--format takes files, files0, ids or json, not \"csv\".\n"]);
});

it('exits 2 where git cannot tell what changed since the ref asked', function () use ($affected): void {
    $printed = $affected('--changed-since=gone', ScriptedRunner::fixture());

    expect([$printed->code, $printed->output, $printed->errors])->toBe([
        2,
        '',
        "Git cannot tell what changed since gone. gone is not a revision this repository has. Run the whole suite.\n",
    ]);
});
