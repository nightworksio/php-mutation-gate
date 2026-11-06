<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\ComparisonOperator;
use NightWorksIO\MutationGate\Core\Subsumption\Negation;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;

/** The original's code a change negates, by its tokens; `nothing` where it negates none. */
function negatedBy(string $from, string $to): string
{
    $change = Change::of(Subsumed::diff($from, $to));
    $negated = Negation::in($change);

    return $negated instanceof NotGiven
        ? 'nothing'
        : implode(' ', array_slice($change->tokens()->before(), $negated[0], $negated[1] - $negated[0]));
}

it('finds the expression a mutant negates by a ! put before it in brackets, a ! put before or taken from an operand, or a comparison swapped for its opposite', function (string $from, string $to, string $negated): void {
    expect(negatedBy($from, $to))->toBe($negated);
})->with([
    'brackets put behind a !' => ['if ($a > $b) {', 'if (!($a > $b)) {', '$a > $b'],
    'a ! before a call' => ['return $order->isPaid();', 'return !$order->isPaid();', '$order -> isPaid ( )'],
    'a ! before a group' => ['return ($a && $b);', 'return !($a && $b);', '( $a && $b )'],
    'a ! taken from a variable' => ['return !$paid;', 'return $paid;', '! $paid'],
    'a comparison swapped' => ['return $a < $b;', 'return $a >= $b;', '$a < $b'],
    'an identity swapped' => ['if ($a === null) {', 'if ($a !== null) {', '$a === null'],
    'brackets put behind a ! inside a negation in brackets' => ['return !($a);', 'return !(!($a));', '$a'],
    'brackets put behind a ! around a negated operand' => ['return !$a;', 'return !(!$a);', '! $a'],
    'brackets put behind a ! around the whole line' => ['$a > $b', '!($a > $b)', '$a > $b'],
    'brackets put behind a ! around a group that ends in brackets' => ['return (($a));', 'return !((($a)));', '( ( $a ) )'],
    'a ! put before a negated operand' => ['return !$a;', 'return !!$a;', '$a'],
    'a ! put before the whole line' => ['$a', '!$a', '$a'],
    'a ! taken from before a negated operand' => ['return !!$a;', 'return !$a;', '! $a'],
    'a ! taken from the start of the line' => ['!$a', '$a', '! $a'],
]);

it('finds no negation in a change that negates nothing', function (string $from, string $to): void {
    expect(negatedBy($from, $to))->toBe('nothing');
})->with([
    'a comparison swapped and an operand changed' => ['return $a < $b;', 'return $a >= $c;'],
    'a minus put before an operand' => ['return $a;', 'return -$a;'],
    'a minus taken from before an operand' => ['return -$a;', 'return $a;'],
    'a ! before a word an operand stops at, in any case' => ['return $a OR $b;', 'return $a !OR $b;'],
    'brackets put behind a ! that close a group before they open another' => ['if (f($a) || g($b)) {', 'if (f(!($a) || g($b))) {'],
    'a ! before a lower-case word an operand stops at' => ['return $a or $b;', 'return $a !or $b;'],
    'a ! and brackets around nothing' => ['f;', 'f!();'],
    'a comparison loosened' => ['return $a < $b;', 'return $a <= $b;'],
    'an operator changed' => ['return $a + $b;', 'return $a - $b;'],
    'a ! before a word an operand stops at' => ['return $a and $b;', 'return !and $b;'],
    'two tokens added' => ['return $a;', 'return ($a);'],
    'a call removed' => ['$this->save();', ''],
]);

it('pairs each comparison with the one true exactly where it is false', function (): void {
    expect(array_map(static fn(ComparisonOperator $comparison): string => $comparison->opposite()->value, ComparisonOperator::cases()))
        ->toBe(['<=', '>', '>=', '<', '!=', '==', '!==', '===']);
});
