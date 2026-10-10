<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitIni;

$config = static fn(string $php): string => sprintf('<?xml version="1.0"?><phpunit><php>%s</php></phpunit>', $php);

it('reads the memory_limit a PHPUnit config sets, the last where it sets it twice', function () use ($config): void {
    expect(PhpUnitIni::memoryIn($config('<ini name="memory_limit" value=" 2G "/>')))
        ->toEqual(MemoryCap::of(2, MemoryUnit::Gigabytes))
        ->and(PhpUnitIni::memoryIn($config('<ini name="memory_limit" value="-1"/>')))->toEqual(MemoryCap::none())
        ->and(PhpUnitIni::memoryIn($config(
            '<ini name="memory_limit" value="-1"/><ini name="error_reporting" value="-1"/><ini name="memory_limit" value="256M"/>',
        )))->toEqual(MemoryCap::of(256, MemoryUnit::Megabytes));
});

it('reads no memory_limit from a config that sets none, one PHP cannot read, or one that is not XML', function (
    string $text,
): void {
    expect(PhpUnitIni::memoryIn($text))->toEqual(NotGiven::value());
})->with([
    'none set' => ['<?xml version="1.0"?><phpunit><php><ini name="error_reporting" value="-1"/></php></phpunit>'],
    'one PHP cannot read' => ['<?xml version="1.0"?><phpunit><php><ini name="memory_limit" value="lots"/></php></phpunit>'],
    'not XML' => ['<phpunit'],
    'empty' => [''],
]);

it('reads where a PHPUnit config has PHP print errors: the last display_errors it sets', function (
    string $text,
    ErrorDisplay|NotGiven $display,
) use ($config): void {
    expect(PhpUnitIni::displayIn($text === '' ? '' : $config($text)))->toEqual($display);
})->with([
    'hidden' => ['<ini name="display_errors" value="0"/>', ErrorDisplay::Nowhere],
    'shown again, last' => ['<ini name="display_errors" value="Off"/><ini name="display_errors" value="On"/>', ErrorDisplay::Stdout],
    'on standard error' => ['<ini name="display_errors" value="stderr"/>', ErrorDisplay::Stderr],
    'another setting' => ['<ini name="memory_limit" value="0"/>', fn(): NotGiven => NotGiven::value()],
    'no config' => ['', fn(): NotGiven => NotGiven::value()],
]);

it('reads no display_errors set outside the php section', function (): void {
    expect(PhpUnitIni::displayIn('<phpunit><ini name="display_errors" value="0"/></phpunit>'))->toEqual(NotGiven::value());
});
