<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\DiscordMessage;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('sends one embed linked to the run, its groups in the description, and lets it mention no one', function (): void {
    $message = DiscordMessage::json(Alert::of(AlertEvent::Failed, Verdicts::failing(), TrendEntry::none()), Previous::ci());

    expect(Decoded::at($message, 'allowed_mentions'))->toBe(['parse' => []])
        ->and(Decoded::at($message, 'embeds'))->toHaveCount(1)
        ->and(Decoded::at($message, 'embeds', 0))->toMatchArray([
            'title' => 'mutation-gate: failed on octo/gate main',
            'url' => 'https://github.example/octo/gate/actions/runs/7',
            'color' => 0xCB_24_31,
        ])
        ->and(Decoded::at($message, 'embeds', 0, 'description'))->toStartWith(
            "**Below the floor**\n`src`: 44.44%, below its floor of 80.00%.\n\n**Failures**\n",
        );
});

it('is red for a failure, green for a recovery, and grey otherwise', function (AlertEvent $event, int $color): void {
    $message = DiscordMessage::json(Alert::of($event, Verdicts::passing(), TrendEntry::none()), Previous::ci());

    expect(Decoded::at($message, 'embeds', 0, 'color'))->toBe($color);
})->with([
    'failed' => [AlertEvent::Failed, 0xCB_24_31],
    'recovered' => [AlertEvent::Recovered, 0x28_A7_45],
    'cannot judge' => [AlertEvent::CannotJudge, 0x6A_73_7D],
    'floor lowered' => [AlertEvent::FloorLowered, 0x6A_73_7D],
]);

it('leaves out the link and the description where there are none', function (): void {
    $message = DiscordMessage::json(
        Alert::of(AlertEvent::FloorLowered, Verdicts::passing(), TrendEntry::none()),
        CiRun::of('octo/gate', 'refs/heads/main', 'c', ''),
    );

    expect(Decoded::at($message, 'embeds', 0))->not->toHaveKeys(['url', 'description']);
});

it('keeps its title and description within what Discord takes, however long the names', function (): void {
    $long = str_repeat('a', 400);
    $run = CiRun::of(sprintf('octo/%s', $long), 'refs/heads/main', 'c', 'https://ci.example/7');
    $message = DiscordMessage::json(Alert::of(AlertEvent::Failed, Verdicts::crowded(), TrendEntry::none()), $run);
    $title = Decoded::at($message, 'embeds', 0, 'title');
    $description = Decoded::at($message, 'embeds', 0, 'description');

    expect(is_string($title) ? mb_strlen($title) : 0)->toBe(256)
        ->and(is_string($description) ? mb_strlen($description) : 0)->toBeGreaterThan(1_800)->toBeLessThanOrEqual(2_000)
        ->and($description)->toEndWith(' more.');
});
