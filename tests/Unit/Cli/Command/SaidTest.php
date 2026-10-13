<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\Said;
use NightWorksIO\MutationGate\Core\CannotJudge;

it('says what was made in order, leaving out what made nothing', function (): void {
    expect(Said::joined('Wrote a.', '', 'Wrote b.'))->toBe("Wrote a.\nWrote b.")
        ->and(Said::joined())->toBe('');
});

it('says what was made before the first that could not be, and why, and nothing after', function (): void {
    expect(Said::joined('Wrote a.', CannotJudge::because('b could not be written.'), 'Wrote c.', CannotJudge::because('d.')))
        ->toEqual(CannotJudge::because("Wrote a.\nb could not be written."));
});

it('makes a step given as a closure once every step before it was made, and says what it made', function (): void {
    $made = [];
    $step = static function (string $said) use (&$made): Closure {
        return static function () use ($said, &$made): string {
            $made[] = $said;

            return $said;
        };
    };

    expect(Said::joined('Wrote a.', $step('Wrote b.'), static fn(): string => ''))->toBe("Wrote a.\nWrote b.")
        ->and(Said::joined($step('Wrote c.'), static fn(): CannotJudge => CannotJudge::because('d.'), $step('Wrote e.')))
        ->toEqual(CannotJudge::because("Wrote c.\nd."))
        ->and($made)->toBe(['Wrote b.', 'Wrote c.']);
});
