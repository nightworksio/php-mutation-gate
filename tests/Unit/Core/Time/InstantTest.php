<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Instant;

it('writes the moment a clock read in UTC, to the second', function (): void {
    $moment = new DateTimeImmutable('2026-09-29 22:48:17.734', new DateTimeZone('Europe/Amsterdam'));

    expect(Instant::at($moment)->value())->toBe('2026-09-29T20:48:17Z');
});

it('reads an instant as the gate writes it', function (): void {
    expect(Instant::parse('2026-09-29T20:48:17Z'))->toEqual(Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')));
});

it('refuses anything else, saying how an instant is written', function (string $written): void {
    expect(Instant::parse($written))
        ->toEqual(CannotJudge::because(sprintf('"%s" is not an instant. Write it as 2026-09-29T20:48:17Z.', $written)));
})->with(['2026-09-29 20:48:17', '2026-09-29T20:48:17+02:00', "2026-09-29T20:48:17Z\n", 'x2026-09-29T20:48:17Z']);

it('orders instants by when they were', function (): void {
    $earlier = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $later = Instant::at(new DateTimeImmutable('2026-09-29T20:48:18Z'));

    expect($later->isAfter($earlier))->toBeTrue()
        ->and($earlier->isAfter($later))->toBeFalse()
        ->and($earlier->isAfter($earlier))->toBeFalse();
});
