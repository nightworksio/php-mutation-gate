<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Tree\Outside;

it('stands for a path no tree holds', function (): void {
    expect(Outside::trees())->toBeInstanceOf(Outside::class);
});
