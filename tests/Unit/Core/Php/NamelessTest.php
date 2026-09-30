<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Nameless;

it('is the one name code without a name has, whoever names it', function (): void {
    expect(Nameless::code())->toEqual(Nameless::code())
        ->and(Nameless::code())->toBeInstanceOf(Nameless::class);
});
