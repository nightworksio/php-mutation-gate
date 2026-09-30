<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Php\Tokens;

use function sprintf;
use function str_starts_with;

/**
 * The assertions made between two tokens: PHPUnit's `assert…` and
 * `expect…` calls, as methods or functions, and each expectation chained
 * after Pest's `expect()`, with `->not` before it where it is negated, and a
 * test's `->throws()`. One the table does not hold leaves the test not
 * assessed.
 */
final readonly class AssertionScan
{
    private const string EXPECT = 'expect';

    private const string ASSERT = 'assert';

    /** What Pest's expectations are named with. */
    private const string TO = 'to';

    private const string NOT = 'not';

    /** A chained call that starts a new subject, and so ends a negation. */
    private const string AND = 'and';

    /** A Pest test's chained expectation of an exception. */
    private const string THROWS = 'throws';

    private const string PEST = '->%s()';

    private const string PEST_NEGATED = '->not->%s()';

    /** How far past a `->` the bracket of the call it names opens. */
    private const int CALL_OPENS = 3;

    private function __construct(private Tokens $tokens)
    {
    }

    /** The assertions made from one index to another. */
    public static function between(Tokens $tokens, int $from, int $to): Assertions
    {
        $scan = new self($tokens);
        $assertions = Assertions::of();

        for ($at = $from; $at <= $to; ++$at) {
            foreach ($scan->madeAt($at) as $assertion) {
                if ($assertion instanceof Unclassified) {
                    return Assertions::notAssessed();
                }

                $assertions = $assertions->with($assertion);
            }
        }

        return $assertions;
    }

    /** Where a call that closes at an index ends with every call chained after it. */
    public static function chainEnd(Tokens $tokens, int $closer): int
    {
        $end = $closer;

        while ($tokens->is($end + 1, T_OBJECT_OPERATOR) && $tokens->is($end + 2, T_STRING)) {
            $end = $tokens->is($end + self::CALL_OPENS, '(') ? $tokens->closing($end + self::CALL_OPENS) : $end + 2;
        }

        return $end;
    }

    /** Whether the name at an index is a method or a static member, as `->name` or `::name` write it. */
    public static function isMember(Tokens $tokens, int $at): bool
    {
        return $tokens->is($at - 1, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION);
    }

    /** @return list<Assertion|Unclassified> the assertions a call at this index makes; none where none begins there */
    private function madeAt(int $at): array
    {
        if (! $this->tokens->is($at, T_STRING) || ! $this->tokens->is($at + 1, '(')) {
            return [];
        }

        $name = $this->tokens->text($at);
        $member = self::isMember($this->tokens, $at);

        return match (true) {
            ! $member && mb_strtolower($name) === self::EXPECT => $this->chained($this->tokens->closing($at + 1)),
            $this->tokens->is($at - 1, T_FUNCTION) => [],
            str_starts_with($name, self::ASSERT), str_starts_with($name, self::EXPECT) => [$this->phpUnit($at, $name)],
            $member && $name === self::THROWS => [Assertion::of(sprintf(self::PEST, $name), AssertionKind::Value)],
            default => [],
        };
    }

    private function phpUnit(int $at, string $name): Assertion|Unclassified
    {
        $argument = mb_strtolower($this->tokens->spelt($at + 2, $this->tokens->closing($at + 1)));
        $kind = AssertionTable::phpUnit($name, $argument);

        return $kind instanceof AssertionKind ? Assertion::of($name, $kind) : $kind;
    }

    /**
     * Each expectation chained after an `expect()` that closes at an index.
     *
     * @return list<Assertion|Unclassified>
     */
    private function chained(int $closer): array
    {
        $made = [];
        $negated = false;

        foreach ($this->links($closer) as [$name, $called]) {
            $expectation = $called && str_starts_with($name, self::TO);

            if ($expectation) {
                $made[] = $this->pest($name, $negated);
            }

            $negated = ! $expectation && $name !== self::AND && ($negated xor $name === self::NOT);
        }

        return $made;
    }

    /**
     * Each link chained after a call that closes at an index: its name, and whether it is called.
     *
     * @return list<array{string, bool}>
     */
    private function links(int $closer): array
    {
        $links = [];
        $at = $closer;

        while ($this->tokens->is($at + 1, T_OBJECT_OPERATOR) && $this->tokens->is($at + 2, T_STRING)) {
            $called = $this->tokens->is($at + self::CALL_OPENS, '(');
            $links[] = [$this->tokens->text($at + 2), $called];
            $at = $called ? $this->tokens->closing($at + self::CALL_OPENS) : $at + 2;
        }

        return $links;
    }

    private function pest(string $name, bool $negated): Assertion|Unclassified
    {
        $kind = AssertionTable::pest($name, $negated);

        return $kind instanceof AssertionKind
            ? Assertion::of(sprintf($negated ? self::PEST_NEGATED : self::PEST, $name), $kind)
            : $kind;
    }
}
