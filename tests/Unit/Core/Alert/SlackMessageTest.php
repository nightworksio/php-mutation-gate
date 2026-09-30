<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\SlackMessage;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('sends the title as the fallback, then a header, a section per group and the run', function (): void {
    $message = SlackMessage::json(Alert::of(AlertEvent::Recovered, Verdicts::passing(), TrendEntry::none()), Previous::ci());

    expect(Decoded::at($message))->toBe([
        'text' => 'mutation-gate: recovered on octo/gate main',
        'blocks' => [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'mutation-gate: recovered on octo/gate main']],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Trees*\n`src`: 100.00% against its floor of 80.00%."]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '<https://github.example/octo/gate/actions/runs/7|The run>']],
        ],
    ]);
});

it('keeps every block within what Slack takes, however long the names', function (): void {
    $long = str_repeat('a', 400);
    $run = CiRun::of(sprintf('octo/%s', $long), sprintf('refs/heads/%s', $long), 'c', 'https://ci.example/7');
    $message = SlackMessage::json(Alert::of(AlertEvent::Failed, Verdicts::crowded(), TrendEntry::none()), $run);
    $blocks = Decoded::at($message, 'blocks');
    $lengths = [];

    foreach (is_array($blocks) ? $blocks : [] as $block) {
        $text = is_array($block) && is_array($block['text']) ? $block['text']['text'] : '';
        $lengths[] = is_string($text) ? mb_strlen($text) : 0;
    }

    expect($lengths)->toHaveCount(4)
        ->and($lengths[0])->toBe(150)
        ->and(max(0, ...$lengths))->toBeLessThanOrEqual(3_000)
        ->and($lengths[1])->toBeGreaterThan(2_800)
        ->and(Decoded::at($message, 'blocks', 1, 'text', 'text'))->toEndWith(' more.');
});
