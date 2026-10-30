<?php

declare(strict_types=1);

use Library\Flagged;

it('flags a class and a method, and nothing else', function (): void {
    $declared = new ReflectionClass(Flagged::class)->getAttributes(Attribute::class)[0]->newInstance();

    expect($declared->flags)->toBe(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD);
});
