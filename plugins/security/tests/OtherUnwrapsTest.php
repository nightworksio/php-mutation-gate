<?php

declare(strict_types=1);

use NightWorksIO\MutationGateSecurity\OtherUnwraps;

it('names the default set\'s unwrap by its own name, and Pest\'s by its class', function (): void {
    expect([...OtherUnwraps::named('UnwrapSomething')])->toBe([
        'default/UnwrapSomething',
        'Pest\\Mutate\\Mutators\\String\\UnwrapSomething',
    ]);
});
