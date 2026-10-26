<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('holds a tree to the least floor above nothing, a hundredth of a point', function (): void {
    $trees = Configs::settings(['runner' => 'pest', 'trees' => [['path' => 'src', 'floor' => 0.01]]])->floors()->trees();

    expect($trees instanceof Absent ? [] : array_map(static fn(DeclaredTree $tree): object => $tree->declared(), [...$trees]))
        ->toEqual([Floor::ofHundredths(1)]);
});
