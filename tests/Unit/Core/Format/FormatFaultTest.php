<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\FormatFault;

it('says which step of Format failed, as a mistake in the code rather than in what it was handed', function (FormatFault $fault, string $said): void {
    expect($fault)->toBeInstanceOf(LogicException::class)
        ->and($fault->getMessage())->toBe($said);
})->with([
    'PCRE' => [fn(): FormatFault => FormatFault::pcre(), 'PCRE could not apply a fixed pattern to text that is valid UTF-8.'],
    'gzip' => [fn(): FormatFault => FormatFault::gzip(), 'zlib could not pack text at its default level.'],
    'a JSON key' => [fn(): FormatFault => FormatFault::jsonKey(), 'A key of text already read as JSON did not decode to a string.'],
]);
