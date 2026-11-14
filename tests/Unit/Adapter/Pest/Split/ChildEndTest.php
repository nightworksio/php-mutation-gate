<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildEnd;

it('kills its mutant where the child failed or ended, not where it passed or overran', function (): void {
    expect(array_map(static fn(ChildEnd $end): bool => $end->kills(), ChildEnd::cases()))
        ->toBe([false, true, true, false]);
});
