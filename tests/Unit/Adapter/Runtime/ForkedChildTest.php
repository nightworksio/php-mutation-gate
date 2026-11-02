<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\ForkedChild;

it('seeds PHP\'s generator afresh, so it leaves the sequence it was copied with', function (): void {
    mt_srand(7);
    $copied = [mt_rand(), mt_rand(), mt_rand()];
    mt_srand(7);
    ForkedChild::reseeded();

    expect([mt_rand(), mt_rand(), mt_rand()])->not->toBe($copied);
});
