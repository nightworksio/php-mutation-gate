<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;

it('is the one value nobody gave, whoever names it', function (): void {
    expect(NotGiven::value())->toEqual(NotGiven::value())
        ->and(NotGiven::value())->toBeInstanceOf(NotGiven::class);
});
