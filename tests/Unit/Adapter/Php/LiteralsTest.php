<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\Literals;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;

it('reads true and false in any case as the booleans they are', function (): void {
    expect([Literals::of(new ConstFetch(new Name('TRUE'))), Literals::of(new ConstFetch(new Name('false')))])
        ->toBe([true, false]);
});
