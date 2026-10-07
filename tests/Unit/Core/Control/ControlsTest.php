<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A control of a file by one test, allowed five seconds. */
function controlsEntry(string $file, string $test): Control
{
    return Control::of(Path::of($file), TestIds::of(TestId::of($test)), Seconds::of(5.0));
}

/**
 * The files of some controls, in their order.
 *
 * @return list<string>
 */
function controlledFiles(Controls $controls): array
{
    return array_map(static fn(Control $control): string => $control->file()->value(), [...$controls]);
}

it('holds each control once, in the order first asked for', function (): void {
    $controls = Controls::of(
        controlsEntry('src/Tax.php', 'TaxTest::a'),
        controlsEntry('src/Money.php', 'MoneyTest::a'),
        controlsEntry('src/Tax.php', 'TaxTest::a'),
    )->with(controlsEntry('src/Money.php', 'MoneyTest::a'))->with(controlsEntry('src/Rate.php', 'RateTest::a'));

    expect(controlledFiles($controls))->toBe(['src/Tax.php', 'src/Money.php', 'src/Rate.php'])
        ->and(count($controls))->toBe(3)
        ->and(count(Controls::none()))->toBe(0);
});

it('says whether a control is one of them, by its file, tests and limit', function (): void {
    $controls = Controls::of(controlsEntry('src/Tax.php', 'TaxTest::a'));

    expect($controls->has(controlsEntry('src/Tax.php', 'TaxTest::a')))->toBeTrue()
        ->and($controls->has(controlsEntry('src/Tax.php', 'TaxTest::b')))->toBeFalse()
        ->and(Controls::none()->has(controlsEntry('src/Tax.php', 'TaxTest::a')))->toBeFalse();
});
