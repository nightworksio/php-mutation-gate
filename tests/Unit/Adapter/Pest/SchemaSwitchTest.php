<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;

it('keeps the mutant in force of each file, and turns one file\'s off alone', function (): void {
    SchemaSwitch::on('a money test', 1);
    SchemaSwitch::on('a tax test', 2);
    $both = [SchemaSwitch::is('a money test', 1), SchemaSwitch::is('a tax test', 2), SchemaSwitch::is('a tax test', 1)];
    SchemaSwitch::off('a tax test');
    $money = [SchemaSwitch::is('a money test', 1), SchemaSwitch::is('a tax test', 2)];
    SchemaSwitch::off('a money test');

    expect([$both, $money, SchemaSwitch::is('a money test', 1)])->toBe([[true, true, false], [true, false], false]);
});
