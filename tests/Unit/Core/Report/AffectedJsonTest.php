<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Report\AffectedJson;
use NightWorksIO\MutationGate\Tests\Support\Affected;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

$commit = '0123456789abcdef0123456789abcdef01234567';

it('writes the base, where the map was measured, whether all are listed, each file with its ids and reasons, and what is unreached', function () use (
    $commit,
): void {
    $tests = AffectedTests::none(Affected::places())
        ->wholly(Path::of('tests/RateTest.php'), Reason::that('`src/Money.php` declares constant App\Money::RATE, which these tests read.'))
        ->leaving(Path::of('src/Lone.php'), Reason::that('Left.'));

    expect(json_decode(AffectedJson::of($tests, Revision::ref('origin/main'), MeasuredAt::of(Revision::ref($commit), dirty: false)), associative: true))
        ->toBe([
            'format' => 1,
            'base' => 'origin/main',
            'map' => ['commit' => $commit, 'dirty' => false],
            'all' => false,
            'tests' => [[
                'file' => 'tests/RateTest.php',
                'ids' => ['RateTest::rates'],
                'reasons' => ['`src/Money.php` declares constant App\Money::RATE, which these tests read.'],
            ]],
            'unreached' => ['src/Lone.php'],
        ]);
});

it('reads the change from the map\'s commit where no ref is given, and lists every file where all are', function () use (
    $commit,
): void {
    $json = AffectedJson::of(
        AffectedTests::every(Affected::places(), Reason::that('All.')),
        NotGiven::value(),
        MeasuredAt::of(Revision::ref($commit), dirty: true),
    );

    expect([Decoded::at($json, 'base'), Decoded::at($json, 'map'), Decoded::at($json, 'all'), Decoded::column($json, 'file', 'tests')])->toBe([
        $commit,
        ['commit' => $commit, 'dirty' => true],
        true,
        ['tests/MoneyTest.php', 'tests/PriceTest.php', 'tests/RateTest.php'],
    ]);
});

it('writes a null base and map where there is no ref and no map that says where it was measured', function (
    Unplaced|NotGiven $map,
): void {
    $json = AffectedJson::of(AffectedTests::every(Affected::places(), Reason::that('All.')), NotGiven::value(), $map);

    expect([Decoded::at($json, 'base'), Decoded::at($json, 'map')])->toBe([null, null]);
})->with([[fn(): Unplaced => Unplaced::map()], [fn(): NotGiven => NotGiven::value()]]);

it('writes a # inertly, so a CI log reads no command in it', function (): void {
    $tests = AffectedTests::none(Affected::places())->wholly(Path::of('tests/RateTest.php'), Reason::that('##[error] it'));

    expect(AffectedJson::of($tests, NotGiven::value(), NotGiven::value()))->not->toContain('##[');
});

it('holds a hostile path as one string, so no line it prints starts a command', function (): void {
    $path = "tests/a\n::add-mask::x ##[error].php";
    $tests = AffectedTests::none(TestPlaces::none()->placing(Path::of($path), Affected::ids('HostileTest::runs')))
        ->wholly(Path::of($path), Reason::that('Changed.'));
    $json = AffectedJson::of($tests, NotGiven::value(), NotGiven::value());

    expect(preg_grep('/^\s*::|##\[/', explode("\n", $json)))->toBe([])
        ->and(Decoded::column($json, 'file', 'tests'))->toBe([$path]);
});
