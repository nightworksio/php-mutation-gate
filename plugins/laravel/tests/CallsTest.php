<?php

declare(strict_types=1);

use NightWorksIO\MutationGateLaravel\Calls;
use PhpParser\Node\Identifier;

it('matches a call\'s name to a method named in any case, as PHP calls it', function (): void {
    expect(Calls::named(new Identifier('FIRSTORFAIL'), 'firstOrFail'))->toBeTrue()
        ->and(Calls::named(new Identifier('first'), 'firstOrFail'))->toBeFalse();
});
