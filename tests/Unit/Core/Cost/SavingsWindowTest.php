<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\SavedLately;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Moment;

it('says its length in days where it is whole days, and as a config writes it where it is not', function (Seconds $length, string $said): void {
    expect(SavingsWindow::of($length)->said())->toBe($said);
})->with([
    'thirty days' => [fn(): Seconds => Seconds::days(30), '30 days'],
    'one day' => [fn(): Seconds => Seconds::days(1), '1 day'],
    'two days' => [fn(): Seconds => Seconds::days(2), '2 days'],
    'a day and a half' => [fn(): Seconds => Seconds::of(129_600.0), '1d12h'],
    'twelve hours' => [fn(): Seconds => Seconds::of(43_200.0), '12h'],
    'no time' => [fn(): Seconds => Seconds::none(), '0 days'],
]);

it('counts thirty days by default, from that long before now', function (): void {
    $now = new DateTimeImmutable('2026-09-30T12:00:00Z');

    expect(SavingsWindow::standard()->length())->toEqual(Seconds::days(30))
        ->and(SavingsWindow::standard()->since($now))->toEqual(Moment::at('2026-08-31T12:00:00Z'))
        ->and(SavingsWindow::of(Seconds::of(90.0))->since($now))->toEqual(Moment::at('2026-09-30T11:58:30Z'));
});

it('pairs what was saved with itself, and keeps no history as none', function (): void {
    $window = SavingsWindow::of(Seconds::days(7));

    expect($window->saved(Seconds::of(60.0)))->toEqual(SavedLately::of(Seconds::of(60.0), $window))
        ->and($window->saved(NoHistory::yet()))->toEqual(NoHistory::yet());
});

it('reads the window a reporter\'s options hold, the standard one where they hold none or no duration', function (string $options, SavingsWindow $window): void {
    expect(SavingsWindow::in(Configs::options($options)))->toEqual($window);
})->with([
    'a week' => ['{"savingsWindow": "7d"}', fn(): SavingsWindow => SavingsWindow::of(Seconds::days(7))],
    'none' => ['{}', fn(): SavingsWindow => SavingsWindow::standard()],
    'no duration' => ['{"savingsWindow": "a month"}', fn(): SavingsWindow => SavingsWindow::standard()],
    'no text' => ['{"savingsWindow": 7}', fn(): SavingsWindow => SavingsWindow::standard()],
]);

it('reads a published window, and refuses one that is no duration', function (): void {
    expect(SavingsWindow::read(Node::config('"36h"')))->toEqual(SavingsWindow::of(Seconds::of(129_600.0)))
        ->and(static fn(): SavingsWindow => SavingsWindow::read(Node::config('"a month"')))->toThrow(NotInShape::class, 'is not a duration');
});

it('lays itself beneath the options of the summary and the badge only, under what they say', function (string $use, string $written): void {
    expect(SavingsWindow::of(Seconds::days(7))->under($use, Configs::options('{"path": "x"}'))->written()->line())->toBe($written);
})->with([
    'the step summary' => [BuiltinReporter::GitHubSummary->value, '{"savingsWindow":"7d","path":"x"}'],
    'the badge' => [BuiltinReporter::Badge->value, '{"savingsWindow":"7d","path":"x"}'],
    'the JSON report' => [BuiltinReporter::Json->value, '{"path":"x"}'],
    'a reporter of an extension' => ['\\Acme\\Reporter', '{"path":"x"}'],
]);

it('leaves a window its options already hold', function (): void {
    expect(SavingsWindow::standard()->under(BuiltinReporter::Badge->value, Configs::options('{"savingsWindow": "1d"}'))->written()->line())
        ->toBe('{"savingsWindow":"1d"}');
});
