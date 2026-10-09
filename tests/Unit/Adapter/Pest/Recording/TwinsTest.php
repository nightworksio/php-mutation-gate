<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Twins;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps out a mutant that leaves its file as one made before it does, as that one\'s twin, and no other', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    Scratch::write($root, 'src/Tax.php', '<?php');
    $money = sprintf('%s/src/Money.php', $root);
    $tax = sprintf('%s/src/Tax.php', $root);
    $results = sprintf('%s/results.jsonl', $root);

    $made = [
        Twins::isTwin(Mutations::mutation($money, 'a', 11, '/tmp/copy-1'), $results),
        Twins::isTwin(Mutations::mutation($money, 'b', 11, '/tmp/copy-1'), $results),
        Twins::isTwin(Mutations::mutation($money, 'c', 12, '/tmp/copy-2'), $results),
        Twins::isTwin(Mutations::mutation($tax, 'd', 11, '/tmp/copy-1'), $results),
        Twins::isTwin(Mutations::mutation($money, 'e', 11, '/tmp/copy-1'), sprintf('%s/other.jsonl', $root)),
    ];

    expect($made)->toBe([false, true, false, false, false])
        ->and(array_map(static fn(PlannedMutant $twin): string => $twin->id(), Twins::of($results)))->toBe(['b'])
        ->and(Twins::of(sprintf('%s/other.jsonl', $root)))->toBe([]);
});

it('keeps out no mutant where the gate records nothing', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    $file = sprintf('%s/src/Money.php', $root);

    expect(Twins::isTwin(Mutations::mutation($file, 'a', 11, '/tmp/copy-1'), ''))->toBeFalse()
        ->and(Twins::isTwin(Mutations::mutation($file, 'b', 11, '/tmp/copy-1'), ''))->toBeFalse()
        ->and(Twins::of(''))->toBe([]);
});
