<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Pruning;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('prunes by default on a window of 500 mutants, carrying results up to a week old', function (): void {
    $pruning = Layer::standard()->reach()->pruning();

    expect([$pruning->enabled(), $pruning->window()->mutants(), $pruning->audit()])->toEqual([true, 500, Seconds::days(7)]);
});

it('lays each pruning key a later layer sets over an earlier one\'s, key by key', function (): void {
    $earlier = Layer::of(Reach::of(pruning: Pruning::of(enabled: false, window: 200)));
    $later = Layer::of(Reach::of(pruning: Pruning::of(window: 300, audit: Seconds::days(3))));
    $pruning = $earlier->over($later)->reach()->pruning();

    expect([$pruning->enabled(), $pruning->window()->mutants(), $pruning->audit()])->toEqual([false, 300, Seconds::days(3)])
        ->and($earlier->over(Layer::none())->reach()->pruning()->window()->mutants())->toBe(200)
        ->and(Layer::none()->over($later)->reach()->pruning()->window()->mutants())->toBe(300);
});

it('writes only the pruning keys a layer sets, and the builder calls for them', function (): void {
    $layer = Layer::of(Reach::of(pruning: Pruning::of(enabled: true, audit: Seconds::days(3))));

    expect($layer->written(ProjectRoot::origin())->line())->toBe('{"pruning":{"enabled":true,"audit":"3d"}}')
        ->and($layer->php(ProjectRoot::origin())->code())->toContain('Pruning::on()')
        ->and($layer->php(ProjectRoot::origin())->code())->toContain("Pruning::auditEvery('3d')")
        ->and(Layer::of(Reach::of(pruning: Pruning::of(enabled: new Absent())))->written(ProjectRoot::origin())->line())->toBe('{}');
});
