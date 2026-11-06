<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Report\HeatmapJson;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Summary;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Heatmaps;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes every file for the report, lowest first, each with its functions, and nothing where it scored none', function (): void {
    expect(HeatmapJson::report(Heatmaps::fallen()))->toBe(['heatmap' => [
        ['path' => 'src/Cart.php', 'score' => 50.0, 'base' => 50.0, 'notKilled' => 1, 'functions' => []],
        ['path' => 'src/Money.php', 'score' => 50.0, 'base' => 75.0, 'notKilled' => 2, 'functions' => [
            ['name' => 'Money::sub', 'score' => 50.0, 'base' => 100.0, 'notKilled' => 1],
            ['name' => 'Money::add', 'score' => 100.0, 'base' => 100.0, 'notKilled' => 0],
        ]],
    ]])
        ->and(HeatmapJson::report(Heatmap::none()))->toBe([]);
});

it('writes the lowest-scoring files it lists for the summary, and nothing where it lists none', function (): void {
    $unscored = Heatmap::of(Heatmaps::tree(80, [['src/Cart.php:3', MutantJudgement::Ignored, false]]), Heatmaps::spans(...));

    expect(HeatmapJson::summary(Heatmaps::fallen()->listing(HeatmapRows::of(files: 1, comment: 0))))->toBe(['heatmap' => [
        ['path' => 'src/Cart.php', 'score' => 50.0, 'base' => 50.0, 'notKilled' => 1],
    ]])
        ->and(HeatmapJson::summary(Heatmaps::fallen()->listing(HeatmapRows::of(files: 0, comment: 5))))->toBe([])
        ->and(HeatmapJson::report($unscored))->toBe(['heatmap' => [['path' => 'src/Cart.php', 'notKilled' => 0, 'functions' => []]]]);
});

it('writes a report and a summary with a heatmap that their committed schemas describe', function (): void {
    $verdict = Verdicts::failing();
    $heated = $verdict->withAccount($verdict->account()->withHeatmap(Heatmaps::fallen()));
    $summary = Summary::json($heated, Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(95), NoHistory::yet());

    expect(Schema::errors(JsonReport::encode($heated), Schema::at('resources/report.schema.json')))->toBe([])
        ->and(Schema::errors($summary, Schema::at('resources/summary.schema.json')))->toBe([])
        ->and(JsonReport::of($heated)['heatmap'] ?? [])->toHaveCount(2)
        ->and(Summary::of($heated, Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(95), NoHistory::yet())['heatmap'] ?? [])->toHaveCount(2)
        ->and(JsonReport::of($verdict))->not->toHaveKey('heatmap');
});
