<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Report\TrendSvg;

$trend = static fn(float ...$scores): Trend => Trend::decode((string) json_encode(['runs' => array_map(
    static fn(float $score): array => ['commit' => 'c', 'time' => '2026-09-30T10:00:00Z', 'score' => $score, 'trees' => []],
    $scores,
)]));

it('draws the scores as a sparkline across the width, 100 at the top', function () use ($trend): void {
    expect(TrendSvg::of($trend(100.0, 50.0, 0.0)))->toBe(implode("\n", [
        '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="40" viewBox="0 0 240 40" role="img">',
        '<title>Mutation score over 3 runs, 0.00% at the last</title>',
        '<polyline fill="none" stroke="#4c1" stroke-width="2" points="0.0,0.0 120.0,20.0 240.0,40.0"/>',
        '</svg>',
        '',
    ]));
});

it('draws one run as a point, and none as an empty line', function () use ($trend): void {
    expect(TrendSvg::of($trend(87.41)))->toContain('points="0.0,5.0"')
        ->and(TrendSvg::of($trend(87.41)))->toContain('<title>Mutation score over 1 run, 87.41% at the last</title>')
        ->and(TrendSvg::of(Trend::none()))->toContain('<title>Mutation score: no runs yet</title>')
        ->and(TrendSvg::of(Trend::none()))->toContain('points=""');
});

it('holds no script', function () use ($trend): void {
    expect(TrendSvg::of($trend(50.0, 60.0)))->not->toContain('<script');
});
