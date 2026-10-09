<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PrunedList;

it('writes the mutators a run leaves out and the files it leaves them out of, from the project\'s root, and reads them back', function (): void {
    $text = PrunedList::text(Pruned::of(MutatorNames::of('Plus', 'Minus'), Paths::of(Path::of('src/Money.php'))), '/project/');
    $read = PrunedList::read($text);

    expect($text)->toBe('[["Minus","Plus"],["/project/src/Money.php"]]')
        ->and($read->leavesOut(Path::of('/project/src/Money.php'), RunnerMutatorName::of('Plus')))->toBeTrue()
        ->and($read->leavesOut(Path::of('/project/src/Money.php'), RunnerMutatorName::of('Minus')))->toBeTrue()
        ->and($read->leavesOut(Path::of('/project/src/Money.php'), RunnerMutatorName::of('Times')))->toBeFalse()
        ->and($read->leavesOut(Path::of('/project/src/Tax.php'), RunnerMutatorName::of('Plus')))->toBeFalse()
        ->and(PrunedList::beside('/tmp/results.jsonl'))->toBe('/tmp/results.jsonl.pruned');
});

it('leaves nothing out where there is no list, or it is not one', function (string|false $text): void {
    expect(PrunedList::read($text)->isNone())->toBeTrue();
})->with([
    'no file' => [false],
    'not JSON' => ['[["Plus"'],
    'no lists' => ['{"mutators": "Plus", "files": 5}'],
    'nothing but other values' => ['[[1], [true]]'],
]);

it('reads the strings a list holds and passes over the rest', function (): void {
    $read = PrunedList::read('[["Plus", 3], [false, "/p/src/A.php"]]');

    expect([...$read->mutators()])->toBe(['Plus'])
        ->and($read->leavesOut(Path::of('/p/src/A.php'), RunnerMutatorName::of('Plus')))->toBeTrue();
});
