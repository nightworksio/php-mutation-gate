<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\PrunedRecord;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;

it('writes the mutators a plan prunes and the units it prunes them in, and reads them back', function (): void {
    $pruned = Pruned::of(MutatorNames::of('Plus', 'Minus'), Paths::of(Path::of('src/A.php'), Path::of('src/B.php')));

    expect(PrunedRecord::field($pruned))->toBe(['pruned' => [['Minus', 'Plus'], ['src/A.php', 'src/B.php']]])
        ->and(PrunedRecord::read(Node::decode((string) json_encode(PrunedRecord::field($pruned)))->field('pruned')))->toEqual($pruned)
        ->and(PrunedRecord::field(Pruned::none()))->toBe([])
        ->and(PrunedRecord::read(Node::decode('{}')->field('pruned')))->toEqual(Pruned::none());
});

it('refuses a pruned field not shaped as two lists', function (string $field): void {
    expect(static fn(): Pruned => PrunedRecord::read(Node::decode($field)))->toThrow(NotInShape::class);
})->with([
    'one list' => ['[["Plus"]]'],
    'three lists' => ['[["Plus"], ["src/A.php"], []]'],
    'a mutator that is no name' => ['[[1], ["src/A.php"]]'],
    'no list' => ['"Plus"'],
]);
