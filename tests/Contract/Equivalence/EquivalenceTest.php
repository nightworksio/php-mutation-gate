<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\OpArrays;
use NightWorksIO\MutationGate\Adapter\Outbreak\ProvenBeforeRun;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

// What outbreak.so proves before a mutant runs (ADR-0130): a mutant whose op
// arrays and declarations are its original's, and no other. The extension
// is the one OUTBREAK_EXTENSION names; with none, nothing is proven.

afterEach(function (): void {
    Scratch::sweep();
});

$prover = static function (): ProvenBeforeRun {
    $extension = getenv('OUTBREAK_EXTENSION');

    return new ProvenBeforeRun(new OpArrays(
        PHP_BINARY,
        is_string($extension) ? $extension : '/nonexistent/outbreak.so',
        Scratch::directory(),
        60.0,
    ));
};

$expected = static fn(array $keys): array => is_string(getenv('OUTBREAK_EXTENSION')) ? $keys : [];

$program = static fn(string $body, string $declared = ''): Contents => Contents::of(sprintf(
    "<?php\n\nfinal class Sum\n{\n%s    public function of(int \$a, int \$b): int\n    {\n        %s\n    }\n}\n",
    $declared,
    $body,
));

it('proves a mutant the compiler folds to its original\'s program', function () use ($prover, $expected, $program): void {
    $proven = $prover()->proven([['folded', $program('return 1 + 1;'), $program('return 2;')]]);

    expect(array_keys($proven->proofs))->toBe($expected(['folded']));
});

it('proves no mutant whose program differs, by an op, a literal deep in an array, a float\'s bits or a line', function (
    string $original,
    string $mutant,
) use ($prover, $program): void {
    $proven = $prover()->proven([['differs', $program($original), $program($mutant)]]);

    expect($proven->proofs)->toBe([]);
})->with([
    'an op' => ['return $a + $b;', 'return $a - $b;'],
    'a nested literal' => ['return count([1, [2, 3]]) + $a;', 'return count([1, [2, 4]]) + $a;'],
    'a float\'s sign' => ['return (int) (0.0 * $a);', 'return (int) (-0.0 * $a);'],
    'a line' => ['return $a;', "\n        return \$a;"],
    'a constant expression' => ['return $a ?: self::class === "x" ? 1 : 2;', 'return $a ?: self::class === "y" ? 1 : 2;'],
]);

it('proves no mutant whose declarations differ where its op arrays do not', function () use ($prover, $program): void {
    $proven = $prover()->proven([[
        'declared',
        $program('return $a;', "    public int \$kept = 1;\n\n"),
        $program('return $a;', "    public int \$kept = 2;\n\n"),
    ]]);

    expect($proven->proofs)->toBe([]);
});

it('proves the mutants after one that does not compile, in a child of their own', function () use ($prover, $expected, $program): void {
    $broken = Contents::of("<?php\n\nfinal class Broken\n{\n    public function of(): void\n    {\n        parent::of();\n    }\n}\n");

    $proven = $prover()->proven([
        ['broken', $broken, $broken],
        ['folded', $program('return 2 * 3;'), $program('return 6;')],
    ]);

    expect(array_keys($proven->proofs))->toBe($expected(['folded']));
});

it('gives the same digest to two mutants that are one program, which judges one as the other\'s twin', function () use ($prover, $program): void {
    $proven = $prover()->proven([
        ['first', $program('return $a + $b;'), $program('return $a - $b;')],
        ['second', $program('return $a + $b;'), $program('return $a - $b;')],
    ]);

    $alike = is_string(getenv('OUTBREAK_EXTENSION'))
        ? $proven->programs['first'] === $proven->programs['second']
        : $proven->programs === [];

    expect($alike)->toBeTrue();
});
