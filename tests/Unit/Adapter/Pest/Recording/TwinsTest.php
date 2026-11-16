<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Twins;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\ControlStructures\IfNegated;
use Pest\Mutate\Mutators\Logical\RemoveNot;
use Symfony\Component\Finder\SplFileInfo;

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

it('keeps out a mutant the negation rule makes one program with one made before it at its site, served as that one\'s copy', function (): void {
    $root = (string) realpath(Scratch::directory());
    $file = static function (string $path, string $code) use ($root): string {
        Scratch::write($root, $path, $code);

        return sprintf('%s/%s', $root, $path);
    };
    $money = $file('src/Money.php', "<?php\nif (! \$ready) {\n    go();\n}\n");
    $removed = $file('copies/removed.php', "<?php\nif (\$ready) {\n    go();\n}\n");
    $negated = $file('copies/negated.php', "<?php\nif (!!\$ready) {\n    go();\n}\n");
    $elsewhere = $file('copies/elsewhere.php', "<?php\nif (!!\$ready) {\n    go();\n}\n\n");
    $results = sprintf('%s/results.jsonl', $root);
    $mutation = static fn(string $id, string $mutator, int $line, string $copy): Mutation
        => new Mutation(new SplFileInfo($money, '', ''), $id, $mutator, $line, $line, '', $copy);

    $made = [
        Twins::isTwin($mutation('removed', RemoveNot::class, 2, $removed), $results),
        Twins::isTwin($mutation('negated', IfNegated::class, 2, $negated), $results),
        Twins::isTwin($mutation('elsewhere', IfNegated::class, 3, $elsewhere), $results),
    ];
    $twins = Twins::of($results);

    expect($made)->toBe([false, true, false])
        ->and(array_map(static fn(PlannedMutant $twin): array => [$twin->id(), $twin->mutated()->value()], $twins))
        ->toBe([['negated', $removed]]);
});
