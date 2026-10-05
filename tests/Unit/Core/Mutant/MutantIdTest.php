<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

/** A mutator's full name, as a runner spells it; no such class is installed. */
const A_BOUNDARY_MUTATOR = 'Example\\Mutators\\SmallerToSmallerOrEqual';

const A_BOUNDARY_DIFF = <<<'DIFF'
    --- Original
    +++ New
    @@ @@
         if ($amount > 0) {
    -        return $amount < $limit;
    +        return $amount <= $limit;
         }
    DIFF;

it('hashes the path, the mutator, the changed lines and the occurrence, each after its length', function (): void {
    $fields = ['src/Money.php', A_BOUNDARY_MUTATOR, '-return $amount < $limit;', '+return $amount <= $limit;', '2'];
    $canonical = implode("\n", array_map(static fn(string $field): string => sprintf('%d:%s', mb_strlen($field), $field), $fields));

    expect(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, A_BOUNDARY_DIFF, 2)->value())->toBe(mb_substr(hash('sha256', $canonical), 0, 12));
});

it('names the same mutant alike wherever its whitespace, its header or its line endings differ', function (): void {
    $same = "--- a/src/Money.php\n+++ b/src/Money.php\n@@ -3,1 +3,1 @@\n-return   \$amount <\t\$limit;   \n+return \$amount <=   \$limit;";

    expect(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, $same, 0))->toEqual(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, A_BOUNDARY_DIFF, 0))
        ->and(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, str_replace("\n", "\r\n", A_BOUNDARY_DIFF), 0))->toEqual(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, A_BOUNDARY_DIFF, 0));
});

it('reads every line as the change when a diff has no hunk', function (): void {
    expect(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, "-return \$amount < \$limit;\n+return \$amount <= \$limit;", 0))
        ->toEqual(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, A_BOUNDARY_DIFF, 0));
});

it('tells mutants apart by anything that makes them different', function (MutantId $other): void {
    expect($other)->not->toEqual(MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, A_BOUNDARY_DIFF, 0));
})->with([
    'another file' => fn(): MutantId => MutantId::hash(Path::of('src/Limit.php'), A_BOUNDARY_MUTATOR, A_BOUNDARY_DIFF, 0),
    'another mutator' => fn(): MutantId => MutantId::hash(Path::of('src/Money.php'), 'Example\\Mutators\\SmallerToGreaterOrEqual', A_BOUNDARY_DIFF, 0),
    'the sides swapped' => fn(): MutantId => MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, "@@ @@\n+return \$amount < \$limit;\n-return \$amount <= \$limit;", 0),
    'another occurrence' => fn(): MutantId => MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, A_BOUNDARY_DIFF, 1),
    'words run together' => fn(): MutantId => MutantId::hash(Path::of('src/Money.php'), A_BOUNDARY_MUTATOR, "@@ @@\n-return\$amount < \$limit;\n+return \$amount <= \$limit;", 0),
]);

it('reads an id as every report prints it', function (): void {
    $id = MutantId::parse('3f9a1c2b7d04');

    expect($id instanceof MutantId ? $id->value() : '')->toBe('3f9a1c2b7d04');
});

it('refuses what is not an id, and says what an id is', function (string $written): void {
    expect(MutantId::parse($written))->toEqual(CannotJudge::because(sprintf('"%s" is not a mutant id. An id is twelve lowercase hex characters, as every report prints it.', $written)));
})->with(['3F9A1C2B7D04', '3f9a1c2b7d0', '3f9a1c2b7d04a', '3f9a1c2b7d0g', "3f9a1c2b7d04\n", '']);

it('keys an id as text no merge or spread numbers afresh, an id of digits alone too', function (): void {
    $digits = MutantId::parse('123456789012');
    $hex = MutantId::parse('3f9a1c2b7d04');
    $keyed = $digits instanceof MutantId && $hex instanceof MutantId
        ? [$digits->key() => 'digits', $hex->key() => 'hex']
        : [];

    expect(array_keys([...$keyed, 'other' => 'other']))->toBe(['m123456789012', 'm3f9a1c2b7d04', 'other'])
        ->and(array_keys(array_merge($keyed, ['other' => 'other'])))->toBe(['m123456789012', 'm3f9a1c2b7d04', 'other']);
});
