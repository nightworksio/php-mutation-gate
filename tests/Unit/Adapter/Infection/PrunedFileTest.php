<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\PrunedFile;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PrunedList;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the list beside the results and reads it in the runner\'s process', function (): void {
    $list = PrunedFile::write(
        PrunedList::beside(sprintf('%s/results.jsonl', Scratch::directory())),
        Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/Money.php'))),
        '/project',
    );

    expect($list)->toEndWith('/results.jsonl.pruned')
        ->and(PrunedFile::leavesOut($list, '/project/src/Money.php', 'Plus'))->toBeTrue()
        ->and(PrunedFile::leavesOut($list, '/project/src/Money.php', 'Minus'))->toBeFalse();
});

it('leaves nothing out where no list is named, or none is there', function (): void {
    expect(PrunedFile::leavesOut('', '/project/src/Money.php', 'Plus'))->toBeFalse()
        ->and(PrunedFile::leavesOut(sprintf('%s/missing.pruned', Scratch::directory()), '/project/src/Money.php', 'Plus'))->toBeFalse();
});
