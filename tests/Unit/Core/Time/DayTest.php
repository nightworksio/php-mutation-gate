<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Day;

it('reads a day written as YYYY-MM-DD', function (): void {
    $day = Day::of('2027-03-31');

    expect($day)->toBeInstanceOf(Day::class)
        ->and($day instanceof Day ? $day->value() : '')->toBe('2027-03-31');
});

it('refuses what is not a day, and says how to write one', function (string $written): void {
    expect(Day::of($written))->toEqual(CannotJudge::because(sprintf('"%s" is not a day. Write it as YYYY-MM-DD.', $written)));
})->with(['2027-02-30', '2027-13-01', '2027-00-10', '27-03-31', '2027-3-31', "2027-03-31\n", 'someday']);

it('tells the day an instant falls on, in its own time zone', function (): void {
    expect(Day::on(new DateTimeImmutable('2026-09-29T23:30:00+02:00'))->value())->toBe('2026-09-29');
});

it('orders days', function (): void {
    $day = Day::on(new DateTimeImmutable('2027-03-31'));

    expect($day->isBefore(Day::on(new DateTimeImmutable('2027-04-01'))))->toBeTrue()
        ->and($day->isBefore(Day::on(new DateTimeImmutable('2027-03-31'))))->toBeFalse()
        ->and($day->isBefore(Day::on(new DateTimeImmutable('2027-03-30'))))->toBeFalse();
});
