<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Path;

it('names the directory of the shard\'s own map and of the plan\'s whole map', function (): void {
    $handed = Handed::maps(Path::of('.mutation-gate/coverage/shard-2'), Path::of('.mutation-gate/coverage'));

    expect($handed->own())->toEqual(Path::of('.mutation-gate/coverage/shard-2'))
        ->and($handed->whole())->toEqual(Path::of('.mutation-gate/coverage'));
});
