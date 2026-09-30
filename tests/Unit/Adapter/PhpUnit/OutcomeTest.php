<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Outcome;

it('writes a line of the outcome and the test\'s id, encoded to stay on its line', function (): void {
    expect(Outcome::Failed->line("T::fails#a b\nc"))->toBe("failed T%3A%3Afails%23a%20b%0Ac\n")
        ->and(Outcome::SEPARATOR)->toBe(' ');
});

it('kills with a test that failed or errored, and with no other', function (): void {
    expect(array_map(static fn(Outcome $outcome): bool => $outcome->kills(), Outcome::cases()))
        ->toBe([false, false, true, true, false, false]);
});
