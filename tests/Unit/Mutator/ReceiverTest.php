<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Receiver;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;

it('tells $this from another variable and from a property of it', function (): void {
    expect(Receiver::isThis(new Variable('this')))->toBeTrue()
        ->and(Receiver::isThis(new Variable('that')))->toBeFalse()
        ->and(Receiver::isThis(new PropertyFetch(new Variable('this'), 'that')))->toBeFalse();
});
