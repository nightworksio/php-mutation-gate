<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\PhpUnitIni;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

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
