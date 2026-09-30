<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Unbounded;

it('is the one absence of a bound, whoever names it', function (): void {
    expect(Unbounded::limit())->toEqual(Unbounded::limit())
        ->and(Unbounded::limit())->toBeInstanceOf(Unbounded::class);
});
