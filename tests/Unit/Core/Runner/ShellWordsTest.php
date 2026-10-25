<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ShellWords;

it('quotes each word, so a shell splits the line back into the same words', function (): void {
    expect(ShellWords::line(['ps', "it's", 'a b', '$HOME']))->toBe("'ps' 'it'\\''s' 'a b' '\$HOME'");
});

it('joins no words into an empty line', function (): void {
    expect(ShellWords::line([]))->toBe('');
});
