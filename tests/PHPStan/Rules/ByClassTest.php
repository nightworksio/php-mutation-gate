<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\ByClass;

it('names each member a class lists as the class and the member', function (): void {
    expect(ByClass::named(['A' => ['ONE', 'two'], 'B' => ['three'], 'C' => []]))->toBe(['A::ONE', 'A::two', 'B::three'])
        ->and(ByClass::named([]))->toBe([]);
});
