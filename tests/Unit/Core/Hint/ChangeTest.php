<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hint\Change;

it('reads the lines a diff removed and added, past its header', function (): void {
    $change = Change::of("--- Original\n+++ New\n@@ @@\n-        if (\$amount < \$limit) {\n+        if (\$amount <= \$limit) {\n");

    expect($change->removed())->toBe('if ($amount < $limit) {')
        ->and($change->added())->toBe('if ($amount <= $limit) {')
        ->and($change->changed())->toBe(['<'])
        ->and($change->original())->toBe('<');
});

it('finds the expression around what changed, as far as a bracket or a statement', function (string $removed, string $added, string $expression): void {
    expect(Change::of(sprintf("@@ @@\n-%s\n+%s\n", $removed, $added))->expression())->toBe($expression);
})->with([
    'a comparison in a condition' => ['if ($amount < $limit) {', 'if ($amount <= $limit) {', '$amount < $limit'],
    'a sum returned' => ['return $a + $b;', 'return $a - $b;', '$a + $b'],
    'a sum assigned' => ['$c = $a + $b;', '$c = $a - $b;', '$a + $b'],
    'a call on one side, written as it is' => ['if (count($xs) > 1) {', 'if (count($xs) >= 1) {', 'count($xs) > 1'],
    'one side of a conjunction' => ['return $a > 1 && $b;', 'return $a >= 1 && $b;', '$a > 1'],
    'an argument among others' => ['f($a, $b < 2, $c);', 'f($a, $b <= 2, $c);', '$b < 2'],
    'a negation added' => ['if ($ready) {', 'if (! $ready) {', '$ready'],
    'an item of an array' => ['$xs = [$a + 1];', '$xs = [$a - 1];', '$a + 1'],
]);

it('names what the original calls where it changed', function (string $removed, string $added, string $call): void {
    expect(Change::of(sprintf("@@ @@\n-%s\n+%s\n", $removed, $added))->call())->toBe($call);
})->with([
    'a method call removed' => ['$this->save($order);', '', 'save'],
    'a function unwrapped' => ['return array_values($xs);', 'return $xs;', 'array_values'],
    'a qualified function' => ['return \\App\\total($xs);', 'return $xs;', 'total'],
    'a static call removed' => ['Log::write($line);', '', 'write'],
    'no call at all' => ['return 3;', 'return 4;', ''],
]);

it('takes a diff without a header whole, and one that only removes', function (): void {
    $change = Change::of("-        \$this->save(\$order);\n");

    expect($change->removed())->toBe('$this->save($order);')
        ->and($change->added())->toBe('')
        ->and($change->original())->toBe('$this->save($order);')
        ->and($change->changed())->toBe(['$this', '->', 'save', '(', '$order', ')', ';']);
});

it('reads a literal that changed, and the whole line where nothing differs', function (): void {
    expect(Change::of("@@ @@\n-return 3;\n+return 4;\n")->original())->toBe('3')
        ->and(Change::of("@@ @@\n-return 3;\n+return 3;\n")->original())->toBe('return 3;')
        ->and(Change::of("@@ @@\n-return 3;\n+return 3;\n")->expression())->toBe('')
        ->and(Change::of('')->removed())->toBe('');
});

it('joins a change over several lines', function (): void {
    $change = Change::of("@@ @@\n-        return \$a\n-            + \$b;\n+        return \$a\n+            - \$b;\n");

    expect($change->removed())->toBe('return $a + $b;')
        ->and($change->expression())->toBe('$a + $b');
});
