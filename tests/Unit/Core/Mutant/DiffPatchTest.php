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

describe('DiffPatch', function (): void {
    $makeOriginal = static fn(): Contents => Contents::of(implode("\n", [
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
        ->onto($makeOriginal(), $at($line));

    $text = static fn(Contents|CannotJudge $patched): string => $patched instanceof Contents ? $patched->text() : $patched->why();

    it('puts a hunk back where its lines stand, nearest the mutant\'s line where they stand twice', function () use ($patched, $text, $makeOriginal): void {
        $original = $makeOriginal();

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

    it('puts a diff back where the mutant\'s line says nothing only where its lines stand in one place', function () use ($makeOriginal, $text): void {
        $original = $makeOriginal();

        $patch = static fn(string $diff): string => $text(DiffPatch::of(Mutation::of('Plus', MutatorFamily::Arithmetic, $diff))
            ->ontoTheOnlyPlace($original, Path::of('src/Money.php')));
        $twice = "@@ @@\n {\n-    return \$a + \$b;\n+    return \$a - \$b;\n }";
        $once = "@@ @@\n function sum(int \$a, int \$b): int\n {\n-    return \$a + \$b;\n+    return \$a - \$b;\n }";

        expect($patch($twice))->toBe('Its diff stands in more than one place in src/Money.php, so the gate cannot tell which is the mutant.')
            ->and($patch($once))->toContain("function sum(int \$a, int \$b): int\n{\n    return \$a - \$b;\n}")
            ->and($patch($once))->toContain("function add(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}")
            ->and($patch("@@ @@\n-    return \$a * \$b;\n+    return \$a / \$b;"))->toBe('Its diff does not apply to src/Money.php as it is now.');
    });

    it('places a hunk by how many context lines lead it, counting the mutant\'s line from one', function () use ($patched, $text, $makeOriginal): void {
        $original = $makeOriginal();

        $lines = explode("\n", $original->text());
        $replaced = static fn(int $at): string => implode("\n", array_replace($lines, [$at => '    return $a - $b;']));
        $diff = "@@ @@\n {\n-    return \$a + \$b;\n+    return \$a - \$b;\n }";

        expect($text($patched($diff, 7)))->toBe($replaced(4))
            ->and($text($patched($diff, 8)))->toBe($replaced(9));
    });

    it('counts a hunk\'s lead up to its first removed or added line alone', function () use ($patched, $text, $makeOriginal): void {
        $original = $makeOriginal();

        $lines = explode("\n", $original->text());
        $removed = array_values(array_diff_key($lines, [9 => true]));
        $added = [...array_slice($lines, 0, 9), '    // checked', ...array_slice($lines, 9)];

        expect($text($patched("@@ @@\n {\n-    return \$a + \$b;\n }", 8)))->toBe(implode("\n", $removed))
            ->and($text($patched("@@ @@\n {\n+    // checked\n     return \$a + \$b;", 8)))->toBe(implode("\n", $added));
    });

    it('drops the blank lines a diff\'s last line break leaves only while both sides end in one', function () use ($patched, $text, $makeOriginal): void {
        $original = $makeOriginal();

        $lines = explode("\n", $original->text());

        expect($text($patched("@@ @@\n-    return \$a + \$b;\n+    return \$a - \$b;\n", 5)))
            ->toBe(implode("\n", array_replace($lines, [4 => '    return $a - $b;'])))
            ->and($text($patched("@@ @@\n }\n-\n", 6)))->toBe(implode("\n", array_values(array_diff_key($lines, [6 => true]))));
    });

    it('puts each later hunk where the mutant\'s line says nothing only where it stands once after the one before', function () use ($makeOriginal): void {
        $original = $makeOriginal();

        $diff = "@@ @@\n-function add(int \$a, int \$b): int\n+function add(int \$a): int\n@@ @@\n-    return \$a + \$b;\n+    return \$a;";
        $patched = DiffPatch::of(Mutation::of('Plus', MutatorFamily::Arithmetic, $diff))->ontoTheOnlyPlace($original, Path::of('src/Money.php'));

        expect($patched)->toEqual(CannotJudge::because('Its diff stands in more than one place in src/Money.php, so the gate cannot tell which is the mutant.'));
    });

    it('leads a hunk by the context before its first change alone, however much follows it', function () use ($patched, $text, $makeOriginal): void {
        $original = $makeOriginal();

        $lines = explode("\n", $original->text());

        expect($text($patched("@@ @@\n {\n-    return \$a + \$b;\n+    return \$a - \$b;\n }\n \n", 8)))
            ->toBe(implode("\n", array_replace($lines, [9 => '    return $a - $b;'])));
    });

    it('puts a later hunk at the first place after the one before, however far the mutant\'s line is', function () use ($patched, $text, $makeOriginal): void {
        $original = $makeOriginal();

        $lines = explode("\n", $original->text());
        $diff = "@@ @@\n-function add(int \$a, int \$b): int\n+function add(int \$a): int\n@@ @@\n-    return \$a + \$b;\n+    return \$a;";

        expect($text($patched($diff, 10)))->toBe(implode("\n", array_replace($lines, [2 => 'function add(int $a): int', 4 => '    return $a;'])));
    });
})->group('holds:src/Core/Mutant/DiffPatch.php');
