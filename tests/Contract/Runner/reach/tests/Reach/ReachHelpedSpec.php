<?php

declare(strict_types=1);

it('uses a helper another test file declares', function (): void {
    expect(reachAmount())->toBeInt();
});
