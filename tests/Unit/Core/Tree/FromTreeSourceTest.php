<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Tree\FromTreeSource;

it('is the one answer of the tree source, whoever names it', function (): void {
    expect(FromTreeSource::trees())->toEqual(FromTreeSource::trees())
        ->and(FromTreeSource::trees())->toBeInstanceOf(FromTreeSource::class);
});
