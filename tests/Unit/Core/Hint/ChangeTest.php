<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Php\Nameless;

it('reads the lines a diff removed and added, past its header', function (): void {
    $change = Change::of("--- Original\n+++ New\n@@ @@\n-        if (\$amount < \$limit) {\n+        if (\$amount <= \$limit) {\n");

    expect($change->removed())->toBe('if ($amount < $limit) {')
        ->and($change->added())->toBe('if ($amount <= $limit) {')
        ->and($change->changed())->toBe(['<'])
        ->and($change->original())->toBe('<');
});

it('finds the expression around what changed, as far as a bracket, a block, a statement or a weaker operator', function (string $removed, string $added, string $expression): void {
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
    'a block opened' => ['if ($ok) { $count++; }', 'if ($ok) { $count--; }', '$count++'],
    'a block closed' => ['match ($x) { 1 => $a + $b }', 'match ($x) { 1 => $a - $b }', '$a + $b'],
    'both branches of a ternary' => ['$c = $ok ? $a + $b : $d;', '$c = $ok ? $a - $b : $d;', '$a + $b'],
    'a key\'s arrow' => ["\$xs = ['total' => \$a + \$b];", "\$xs = ['total' => \$a - \$b];", '$a + $b'],
    'a disjunction' => ['return $a > 1 || $b;', 'return $a >= 1 || $b;', '$a > 1'],
    'a spelt conjunction' => ['return $a > 1 and $b;', 'return $a >= 1 and $b;', '$a > 1'],
    'a spelt disjunction' => ['return $a > 1 or $b;', 'return $a >= 1 or $b;', '$a > 1'],
    'an exclusive disjunction' => ['return $a > 1 xor $b;', 'return $a >= 1 xor $b;', '$a > 1'],
    'a fallback' => ['return $cached ?? $a + $b;', 'return $cached ?? $a - $b;', '$a + $b'],
    'an if without braces' => ['if ($ok) $count++;', 'if ($ok) $count--;', '$count++'],
    'an elseif without braces' => ['elseif ($ok) $count++;', 'elseif ($ok) $count--;', '$count++'],
    'a while without braces' => ['while ($ok) $count++;', 'while ($ok) $count--;', '$count++'],
    'a for without braces' => ['for (;;) $count++;', 'for (;;) $count--;', '$count++'],
    'a foreach without braces' => ['foreach ($xs as $x) $count++;', 'foreach ($xs as $x) $count--;', '$count++'],
    'a sum compared in a condition' => ['if ($a + $b > 1) {', 'if ($a + $b >= 1) {', '$a + $b > 1'],
    'a group that opens the line' => ['($a + $b) * 2;', '($a + $b) / 2;', '($a + $b) * 2'],
    'a condition with a call in it' => ['if (f($a)) $count++;', 'if (f($a)) $count--;', '$count++'],
    'a call in a body without braces' => ['if ($ok) f($a) + 1;', 'if ($ok) f($a) - 1;', 'f($a) + 1'],
    'an else without braces' => ['else $count++;', 'else $count--;', '$count++'],
    'a do without braces' => ['do $count++; while ($ok);', 'do $count--; while ($ok);', '$count++'],
    'a match that follows' => ['return $base + match ($x) {', 'return $base - match ($x) {', '$base + match ($x)'],
    'an echo' => ['echo $a + $b;', 'echo $a - $b;', '$a + $b'],
    'a print' => ['print $a + $b;', 'print $a - $b;', '$a + $b'],
    'a throw' => ['throw $error ?? $fallback;', 'throw $fallback;', '$error ?? $fallback'],
    'a yield' => ['yield $a + $b;', 'yield $a - $b;', '$a + $b'],
    'an object created after it' => ["return \$total + new Number('1');", "return \$total - new Number('1');", "\$total + new Number('1')"],
    'an object created before it' => ['return new Money(1) + $a;', 'return new Money(1) - $a;', 'new Money(1) + $a'],
]);

it('stops the expression at an assignment that combines', function (string $operator): void {
    $change = Change::of(sprintf("@@ @@\n-\$sum %s \$a * \$b;\n+\$sum %s \$a / \$b;\n", $operator, $operator));

    expect($change->expression())->toBe('$a * $b');
})->with(['+=', '-=', '*=', '/=', '.=', '%=', '**=', '&=', '|=', '^=', '<<=', '>>=', '??=']);

it('names what the original calls where it changed', function (string $removed, string $added, string|Nameless $call): void {
    expect(Change::of(sprintf("@@ @@\n-%s\n+%s\n", $removed, $added))->call())->toEqual($call);
})->with([
    'a method call removed' => ['$this->save($order);', '', 'save'],
    'a function unwrapped' => ['return array_values($xs);', 'return $xs;', 'array_values'],
    'a qualified function' => ['return \\App\\total($xs);', 'return $xs;', 'total'],
    'a static call removed' => ['Log::write($line);', '', 'write'],
    'no call at all' => ['return 3;', 'return 4;', Nameless::code()],
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
