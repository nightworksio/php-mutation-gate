<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Console\Messages;

it('makes one message, or each of many, printable text', function (): void {
    $stringable = new class implements Stringable {
        public function __toString(): string
        {
            return "named\e[8m";
        }
    };

    expect(Messages::printable("one\e[2K"))->toBe(['one[2K'])
        ->and(Messages::printable(['a', 7, $stringable, null, ['nested']]))->toBe(['a', '7', 'named[8m', '', '']);
});
