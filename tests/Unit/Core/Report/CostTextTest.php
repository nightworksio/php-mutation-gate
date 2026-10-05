<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\Rate;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Report\CostText;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('folds what a run cost into a section at the end of the comment', function (): void {
    expect(CostText::of(Verdicts::named('accounted')))->toBe(implode("\n", [
        '<details><summary>What this run cost</summary>',
        '',
        '| | Wall | Runner time |',
        '|---|---|---|',
        '| Planned | 7m | 15m |',
        '| Measured | 6m | 14m |',
        '',
        'Reach and proofs spared 41m of runner time.',
        '',
        '</details>',
    ]))
        ->and(CostText::of(Verdicts::failing()))->toBe('');
});

it('says setup is estimated where the CI did not measure it, and prices each figure at the team\'s rate', function (): void {
    $estimated = RunTime::estimated(Seconds::of(360.0), Seconds::of(840.0));
    $cost = Cost::of(RunTime::estimated(Seconds::of(420.0), Seconds::of(900.0)), $estimated, Seconds::of(2_460.0), Seconds::of(60.0))
        ->pricedAt(Rate::perMinute(0.2, 'EUR'));

    expect(CostText::markdown($cost))->toContain(implode("\n", [
        'Reach and proofs spared 41m of runner time.',
        'Setup is estimated at 1m a job, from `shards.setup`.',
        'At 0.20 EUR a runner minute: planned 3.00 EUR, measured 2.80 EUR, spared 8.20 EUR.',
    ]));
});

it('shows the team\'s currency as text, which mentions no one, links nowhere and loads no image', function (): void {
    $cost = Cost::of(RunTime::estimated(Seconds::of(60.0), Seconds::of(60.0)), RunTime::estimated(Seconds::of(60.0), Seconds::of(60.0)), Seconds::of(0.0), Seconds::of(0.0))
        ->pricedAt(Rate::perMinute(1.0, 'EUR @org/security ![](https://x.example/p.png) <img src=x>'));
    $markdown = CostText::markdown($cost);

    expect($markdown)->toContain('At 1.00 EUR &#64;org/security &#33;&#91;&#93;(https:&#47;&#47;x.example/p.png) &lt;img src=x&gt; a runner minute:')
        ->and($markdown)->not->toContain('@org')
        ->and($markdown)->not->toContain('![](');
});
