<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\PrunedList;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the mutators a run leaves out and the files it leaves them out of, from the project\'s root, beside its results', function (): void {
    $list = PrunedList::write(
        PrunedList::beside(sprintf('%s/results.jsonl', Scratch::directory())),
        Pruned::of(MutatorNames::of('Plus', 'Minus'), Paths::of(Path::of('src/Money.php'))),
        '/project/',
    );

    expect($list)->toEndWith('/results.jsonl.pruned')
        ->and(PrunedList::leavesOut($list, '/project/src/Money.php', 'Plus'))->toBeTrue()
        ->and(PrunedList::leavesOut($list, '/project/src/Money.php', 'Minus'))->toBeTrue()
        ->and(PrunedList::leavesOut($list, '/project/src/Money.php', 'Times'))->toBeFalse()
        ->and(PrunedList::leavesOut($list, '/project/src/Tax.php', 'Plus'))->toBeFalse();
});

it('leaves nothing out where no list is named, or it cannot be read', function (string $content): void {
    $list = sprintf('%s/broken.pruned', Scratch::directory());
    file_put_contents($list, $content);

    expect(PrunedList::leavesOut($list, '/project/src/Money.php', 'Plus'))->toBeFalse()
        ->and(PrunedList::leavesOut('', '/project/src/Money.php', 'Plus'))->toBeFalse()
        ->and(PrunedList::leavesOut(sprintf('%s/missing.pruned', Scratch::directory()), '/p/src/A.php', 'Plus'))->toBeFalse();
})->with([
    'not JSON' => ['{"mutators": ['],
    'no lists' => ['{"mutators": "Plus", "files": 5}'],
    'nothing but other values' => ['{"mutators": [1], "files": [true]}'],
]);
