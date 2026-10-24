<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\NotEscaped;

it('says PCRE could not escape a value, as a mistake in the code rather than in what it was handed', function (): void {
    expect(NotEscaped::byPcre())->toBeInstanceOf(LogicException::class)
        ->and(NotEscaped::byPcre()->getMessage())->toBe('PCRE could not escape a value that is valid UTF-8 against a fixed character class.');
});
