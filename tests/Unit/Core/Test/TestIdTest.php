<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\TestId;

it('holds a test as its runner names it', function (): void {
    expect(TestId::of('P\\Tests\\Unit\\MoneyTest::__pest_evaluable_it_adds')->value())->toBe('P\\Tests\\Unit\\MoneyTest::__pest_evaluable_it_adds');
});
