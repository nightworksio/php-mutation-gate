<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\CoverageDrivers;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Runner\IniScan;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use Symfony\Component\Process\Process;

/**
 * What `pcov.enabled` is in a PHP started with these variables over this process's own.
 *
 * @param array<string, string|false> $variables
 */
function pcovEnabledIn(array $variables): string
{
    $process = new Process([PHP_BINARY, '-r', 'echo ini_get("pcov.enabled");'], env: $variables);

    return trim($process->mustRun()->getOutput());
}

/** @param array<string, string> $set */
it('turns pcov off under pest.pcovOff by an ini file scanned after what the command scans, and leaves it wherever the streams record with it', function (bool $pcovOff, bool $xdebugOff, bool $withPcov, array $set): void {
    $at = PestCases::project();
    $command = Command::of('php')->with([IniScan::VARIABLE => '/etc/php.d']);
    $drivers = CoverageDrivers::of(PestCases::drivenBy($pcovOff, $xdebugOff), recordsWithPcov: $withPcov, project: $at);
    $scanned = sprintf('%s/pcov-off', $at->workspace());

    $environment = $drivers->onto($command)->environment();
    $expected = [];

    foreach ($set as $name => $value) {
        $expected[$name] = is_string($value) ? str_replace('{scanned}', $scanned, $value) : $value;
    }

    expect(array_intersect_key($environment, $expected))->toEqual($expected)
        ->and(array_diff_key($environment, $expected, $command->environment()))->toBe([])
        ->and($drivers->isNone())->toBe($set === [IniScan::VARIABLE => '/etc/php.d']);
})->with([
    'both off' => [true, true, false, [IniScan::KEPT => 'set:/etc/php.d', IniScan::ADDED => '{scanned}', 'XDEBUG_MODE' => 'off', IniScan::VARIABLE => '/etc/php.d:{scanned}']],
    'pcov recording' => [true, true, true, ['XDEBUG_MODE' => 'off', IniScan::VARIABLE => '/etc/php.d']],
    'pcov recording, nothing off' => [false, false, true, [IniScan::VARIABLE => '/etc/php.d']],
    'nothing off' => [false, false, false, [IniScan::VARIABLE => '/etc/php.d']],
    'Xdebug left' => [true, false, false, [IniScan::KEPT => 'set:/etc/php.d', IniScan::ADDED => '{scanned}', IniScan::VARIABLE => '/etc/php.d:{scanned}']],
]);

it('writes the ini file that turns pcov off, and a PHP started with it has pcov off, as one started from it by the gate has it on again', function (): void {
    $at = PestCases::project();
    $off = CoverageDrivers::of(PestCases::drivenBy(pcovOff: true, xdebugOff: false), recordsWithPcov: false, project: $at)
        ->onto(Command::of('php'))
        ->environment();
    $environment = array_filter($off, is_string(...));

    expect(file_get_contents(sprintf('%s/pcov-off/pcov.ini', $at->workspace())))->toBe("pcov.enabled=0\n")
        ->and(pcovEnabledIn($environment))->toBe(extension_loaded('pcov') ? '0' : '')
        ->and(pcovEnabledIn([...$environment, ...Withholding::of(Withheld::nothing(), [...getenv(), ...$environment])]))->toBe(pcovEnabledIn([]));
});

it('leaves pcov on in Pest\'s own mutation process, which refuses to mutate without a driver, and tells it the directory its processes scan', function (): void {
    $at = PestCases::project();
    $command = Command::of('php')->with([IniScan::VARIABLE => '/etc/php.d']);
    $off = CoverageDrivers::of(PestCases::drivenBy(pcovOff: true, xdebugOff: true), recordsWithPcov: false, project: $at);
    $on = CoverageDrivers::of(PestCases::drivenBy(pcovOff: false, xdebugOff: false), recordsWithPcov: false, project: $at);

    expect($off->mutating($command)->environment())->toBe([
        IniScan::VARIABLE => '/etc/php.d',
        GateVariable::PcovOff->value => sprintf('%s/pcov-off', $at->workspace()),
        'XDEBUG_MODE' => 'off',
    ])->and($on->mutating($command)->environment())->toBe([IniScan::VARIABLE => '/etc/php.d']);
});
