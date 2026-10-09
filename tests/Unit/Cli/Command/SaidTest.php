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
