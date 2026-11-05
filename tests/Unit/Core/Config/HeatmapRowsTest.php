<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Heatmap as HeatmapBuilder;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$rows = static fn(HeatmapRows $heatmap): array => [$heatmap->files(), $heatmap->comment(), $heatmap->methods()];

it('lists 10 files on the dashboard, 5 in the comment, and each file\'s functions, by default', function () use ($rows): void {
    expect($rows(Layer::standard()->reports()->heatmap()))->toBe([10, 5, true]);
});

it('reads every heatmap key a config writes, and lays a later layer\'s over an earlier one\'s, key by key', function () use ($rows): void {
    $read = Configs::valid(['heatmap' => ['files' => 0, 'comment' => 3, 'methods' => false]])->reports()->heatmap();
    $laid = Layer::of(Reports::heatmapping(HeatmapRows::of(files: 4, methods: false)))
        ->over(Layer::of(Reports::heatmapping(HeatmapRows::of(comment: 0))))
        ->reports()
        ->heatmap();

    expect($rows($read))->toBe([0, 3, false])
        ->and($rows($laid))->toBe([4, 0, false]);
});

it('refuses a heatmap key out of its range', function (array $heatmap, string $at): void {
    $problems = Configs::problems(Configs::layer(['heatmap' => $heatmap]));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith(sprintf('%s: ', $at));
})->with([
    'fewer than no files' => [['files' => -1], 'heatmap.files'],
    'fewer than no comment rows' => [['comment' => -1], 'heatmap.comment'],
    'methods that are no flag' => [['methods' => 'yes'], 'heatmap.methods'],
]);

it('writes only the heatmap keys a layer sets, and the builder calls for them, and the builder writes the same keys', function (): void {
    $layer = Layer::of(Reports::heatmapping(HeatmapRows::of(files: 7, comment: 2, methods: false)));
    $built = Configs::written(Gate::configure()->with(HeatmapBuilder::files(7))->with(HeatmapBuilder::comment(2))->with(HeatmapBuilder::withoutMethods()));
    $code = $layer->php(ProjectRoot::origin())->code();

    expect($layer->written(ProjectRoot::origin())->line())->toBe('{"heatmap":{"files":7,"comment":2,"methods":false}}')
        ->and($code)->toContain('Heatmap::files(7)')
        ->and($code)->toContain('Heatmap::comment(2)')
        ->and($code)->toContain('Heatmap::withoutMethods()')
        ->and(Layer::of(Reports::heatmapping(HeatmapRows::of(methods: true)))->php(ProjectRoot::origin())->code())->toContain('Heatmap::withMethods()')
        ->and($built)->toBe(['heatmap' => ['files' => 7, 'comment' => 2, 'methods' => false]])
        ->and(Layer::of(Reports::heatmapping(HeatmapRows::of()))->php(ProjectRoot::origin())->code())->not->toContain('Heatmap::')
        ->and(Layer::of(Reports::heatmapping(HeatmapRows::of()))->written(ProjectRoot::origin())->line())->not->toContain('heatmap');
});

it('builds every heatmap key from the PHP builder', function () use ($rows): void {
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->with(HeatmapBuilder::files(3))
        ->with(HeatmapBuilder::comment(1))
        ->with(HeatmapBuilder::withMethods()));

    expect($rows($settings->effective()->reports()->heatmap()))->toBe([3, 1, true]);
});
