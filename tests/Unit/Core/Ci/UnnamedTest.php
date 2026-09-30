<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Unnamed;

it('stands for a commit the CI does not name', function (): void {
    expect(Unnamed::commit())->toBeInstanceOf(Unnamed::class);
});
