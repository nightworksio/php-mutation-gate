<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Zstd\ZstdProgram;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\TooLarge;

$program = static fn(): ZstdProgram => ZstdProgram::named(ZstdProgram::PROGRAM);
$onPath = static fn(): bool => array_any(
    explode(PATH_SEPARATOR, (string) getenv('PATH')),
    static fn(string $directory): bool => is_executable(sprintf('%s/%s', $directory, ZstdProgram::PROGRAM)),
);
$ledger = static fn(): string => implode(' ', array_map(static fn(int $i): string => hash('crc32b', (string) $i), range(0, 3_999)));

it('packs text smaller and unpacks what it packs, reading to the very limit', function () use ($program, $ledger): void {
    $text = $ledger();
    $packed = $program()->pack($text, 3);

    expect(is_string($packed) ? strlen($packed) : 0)->toBeGreaterThan(0)->toBeLessThan(strlen($text))
        ->and(str_starts_with(is_string($packed) ? $packed : '', "\x28\xb5\x2f\xfd"))->toBeTrue()
        ->and($program()->unpackAtMost(is_string($packed) ? $packed : '', 'The ledger', strlen($text)))->toBe($text);
})->skip(fn(): bool => ! $onPath(), 'The zstd program is not on this machine\'s path.');

it('packs at the level it is asked, or at the nearest one it has', function () use ($program, $ledger): void {
    $text = $ledger();

    expect($program()->pack($text, 0))->toBe($program()->pack($text, 1))
        ->and($program()->pack($text, 1))->not->toBe($program()->pack($text, 2))
        ->and($program()->pack($text, 25))->toBe($program()->pack($text, 19));
})->skip(fn(): bool => ! $onPath(), 'The zstd program is not on this machine\'s path.');

it('packs no text as a stream of none, and is here', function () use ($program): void {
    $packed = $program()->pack('', 9);

    expect($program()->unpackAtMost(is_string($packed) ? $packed : '', 'The ledger', 0))->toBe('')
        ->and($program()->isHere())->toBeTrue();
})->skip(fn(): bool => ! $onPath(), 'The zstd program is not on this machine\'s path.');

it('says a stream that inflates past the limit is too large, and stops the program', function (int $most) use ($program): void {
    $text = str_repeat('x', 1_000_000);
    $packed = $program()->pack($text, 3);

    expect($program()->unpackAtMost(is_string($packed) ? $packed : '', 'The ledger', $most))
        ->toEqual(TooLarge::because(sprintf('The ledger inflates to more than %d bytes.', $most)));
})->with(['just past it' => [999_999], 'far past it' => [1_000]])
    ->skip(fn(): bool => ! $onPath(), 'The zstd program is not on this machine\'s path.');

it('says why it cannot unpack a stream that is not a whole zstd one, as the program does', function () use ($program): void {
    $read = $program()->unpackAtMost("\x28\xb5\x2f\xfdnot a frame", 'The ledger', 100);
    $why = $read instanceof CannotJudge ? $read->why() : '';

    expect($why)->toStartWith('The ledger is not a whole zstd stream: The zstd program ended with code 1: ')
        ->and($why)->toContain('Decoding error')
        ->and($why)->not->toEndWith(' ')
        ->and($why)->not->toEndWith("\n");
})->skip(fn(): bool => ! $onPath(), 'The zstd program is not on this machine\'s path.');

it('is not here, and packs and unpacks nothing, where no program of its name is on the path, raising no warning', function (): void {
    $absent = ZstdProgram::named('no-such-zstd-program');
    error_clear_last();

    expect($absent->isHere())->toBeFalse()
        ->and($absent->pack('text', 9))->toEqual(CannotJudge::because('The zstd program could not be started.'))
        ->and($absent->unpackAtMost('bytes', 'The ledger', 9))
        ->toEqual(CannotJudge::because('The ledger is not a whole zstd stream: The zstd program could not be started.'))
        ->and(error_get_last())->toBeNull();
});
