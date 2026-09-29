<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Unproved;

it('names the key no proof is stored under', function (): void {
    expect(Unproved::key(Digest::of('9c1e'))->digest())->toEqual(Digest::of('9c1e'));
});
