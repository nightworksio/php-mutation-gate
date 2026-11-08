<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('says where a slice is written and the files it holds', function (): void {
    $slice = MapSlice::of(Path::of('.mutation-gate/coverage/shard-1/map.json.gz'), Paths::of(Path::of('src/A.php')));

    expect($slice->into())->toEqual(Path::of('.mutation-gate/coverage/shard-1/map.json.gz'))
        ->and($slice->files())->toEqual(Paths::of(Path::of('src/A.php')));
});
