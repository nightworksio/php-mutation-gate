<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\DiffPatch;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;

$original = Contents::of(implode("\n", [
    '<?php',
    '',
    'function add(int $a, int $b): int',
    '{',
    '    return $a + $b;',
    '}',
    '',
    'function sum(int $a, int $b): int',
    '{',
    '    return $a + $b;',
    '}',
    '',
]));
$at = static fn(int $line): Location => Location::of(Path::of('src/Money.php'), Line::of($line), Unreported::line());
$patched = static fn(string $diff, int $line): Contents|CannotJudge => DiffPatch::of(Mutation::of('Plus', MutatorFamily::Arithmetic, $diff))
    ->onto($original, $at($line));
$text = static fn(Contents|CannotJudge $patched): string => $patched instanceof Contents ? $patched->text() : $patched->why();

it('puts a hunk back where its lines stand, nearest the mutant\'s line where they stand twice', function () use ($patched, $text, $original): void {
    $diff = "@@ @@\n {\n-    return \$a + \$b;\n+    return \$a - \$b;\n }";
    $changed = static fn(int $at): string => implode("\n", array_replace(
        explode("\n", $original->text()),
        [$at - 1 => '    return $a - $b;'],
    ));

    expect($text($patched($diff, 5)))->toBe($changed(5))
        ->and($text($patched($diff, 10)))->toBe($changed(10));
});

it('reads a diff as each runner writes it: a header, a blank context line, a note on a missing line break, and a last line break', function () use ($patched, $text): void {
    $diff = "--- Original\n+++ New\n@@ @@\n\n function sum(int \$a, int \$b): int\n {\n-    return \$a + \$b;\n+    return \$a * \$b;\n }\n\\ No newline at end of file\n";

    expect($text($patched($diff, 10)))->toContain("function sum(int \$a, int \$b): int\n{\n    return \$a * \$b;\n}")
        ->and($text($patched($diff, 10)))->toContain("function add(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}");
});

it('puts each later hunk after the one before', function () use ($patched, $text): void {
    $diff = "@@ @@\n-    return \$a + \$b;\n+    return \$a;\n@@ @@\n-    return \$a + \$b;\n+    return \$b;";

    expect(explode("\n", $text($patched($diff, 5)))[4])->toBe('    return $a;')
        ->and(explode("\n", $text($patched($diff, 5)))[9])->toBe('    return $b;');
});

it('gives no mutant for a diff that does not apply, or holds no hunk', function (string $diff) use ($patched): void {
    expect($patched($diff, 5))->toEqual(CannotJudge::because('Its diff does not apply to src/Money.php as it is now.'));
})->with([
    'lines the file does not hold' => ["@@ @@\n-    return \$a / \$b;\n+    return \$a * \$b;"],
    'a second hunk with nothing after the first' => ["@@ @@\n-function sum(int \$a, int \$b): int\n+function sum(): int\n@@ @@\n-function add(int \$a, int \$b): int\n+function add(): int"],
    'no hunk' => ["--- Original\n+++ New\n"],
]);

it('adds lines where a hunk only adds', function () use ($patched, $text): void {
    expect($text($patched("@@ @@\n <?php\n+declare(strict_types=1);", 1)))->toStartWith("<?php\ndeclare(strict_types=1);\n\nfunction add(");
});
