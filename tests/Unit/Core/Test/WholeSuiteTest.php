<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\WholeSuite;

it('stands for every test of the suite', function (): void {
    expect(WholeSuite::tests())->toBeInstanceOf(WholeSuite::class);
});
