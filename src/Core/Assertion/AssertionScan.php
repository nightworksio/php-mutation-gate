<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function count;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\OwnMember;
use NightWorksIO\MutationGate\Core\Php\Tokens;

use function sprintf;
use function str_starts_with;

/**
 * The assertions made between two tokens: PHPUnit's `assert…` and
 * `expect…` calls, as methods or functions, qualified or not, and each call
 * chained after Pest's `expect()`, with `->not` before it where it is
 * negated, and a test's `->throws()`. An assertion the table does not hold,
 * a chained call that is neither an expectation nor a change of subject, or
 * a call of a helper the file declares leaves the test not assessed.
 */
final readonly class AssertionScan
{
    private const string EXPECT = 'expect';

    private const string ASSERT = 'assert';

    private const string NOT = 'not';

    private const string PEST = '->%s()';

    private const string PEST_NEGATED = '->not->%s()';

    /**
     * How far the bracket of a chained call opens past the end of what it is
     * chained to: the `->`, the name, then the bracket.
     */
    private const int CALL_OPENS = 3;

    private function __construct(private Tokens $tokens, private Helpers $helpers)
    {
    }

    /**
     * The assertions made from one index to another, among a file's tokens
     * and the helpers a test may call.
     */
    public static function between(Tokens $tokens, Helpers $helpers, int $from, int $to): Assertions
    {
        $scan = new self($tokens, $helpers);
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
        $ends = self::linkEnds($tokens, $closer);

        return $ends === [] ? $closer : $ends[count($ends) - 1];
    }

    /** Whether the name at an index is a method or a static member, as `->name` or `::name` write it. */
    public static function isMember(Tokens $tokens, int $at): bool
    {
        return $tokens->is($at - 1, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON);
    }

    /**
     * Where each link chained after a call that closes at an index ends: a
     * call at its closing bracket, and a property at its name.
     *
     * @return list<int>
     */
    private static function linkEnds(Tokens $tokens, int $closer): array
    {
        $ends = [];
        $end = $closer;

        while ($tokens->is($end + 1, T_OBJECT_OPERATOR) && $tokens->is($end + 2, T_STRING)) {
            $end = $tokens->is($end + self::CALL_OPENS, '(') ? $tokens->closing($end + self::CALL_OPENS) : $end + 2;
            $ends[] = $end;
        }

        return $ends;
    }

    /** @return list<Assertion|Unclassified> the assertions a call at this index makes; none where none begins there */
    private function madeAt(int $at): array
    {
        if (! $this->tokens->is($at, ...Names::TOKENS) || ! $this->tokens->is($at + 1, '(')) {
            return [];
        }

        $name = mb_strtolower(Call::nameAt($this->tokens, $at));
        $member = self::isMember($this->tokens, $at);

        return match (true) {
            $this->declares($at) => [],
            ! $member && $name === self::EXPECT => $this->chained($this->tokens->closing($at + 1)),
            str_starts_with($name, self::ASSERT), str_starts_with($name, self::EXPECT) => [
                $this->phpUnit(Call::at($this->tokens, $at)),
            ],
            $this->callsHelper($at, $name), $this->callsOwnHelper($at, $name) => [Unclassified::assertion()],
            $member => $this->pestTest(Call::at($this->tokens, $at)),
            default => [],
        };
    }

    /** Whether the name at an index is declared there, as `function name(` or `function &name(` write it. */
    private function declares(int $at): bool
    {
        return $this->tokens->is($at - 1, T_FUNCTION)
            || ($this->tokens->is($at - 1, '&') && $this->tokens->is($at - 2, T_FUNCTION));
    }

    /** Whether a call at an index is of a helper function, whose own assertions a scan of the test does not read. */
    private function callsHelper(int $at, string $name): bool
    {
        return ! self::isMember($this->tokens, $at) && $this->helpers->has($name);
    }

    /**
     * Whether a call at an index, of no assertion, is of a method the test
     * case holds that is not one of PHPUnit's own, such as a trait's or a
     * parent class's helper, whose assertions the scan does not read.
     */
    private function callsOwnHelper(int $at, string $name): bool
    {
        return OwnMember::calledAt($this->tokens, $at - 1) && ! AssertionTable::isCaseMethod(Call::of($name));
    }

    /** @return list<Assertion> the exception a call chained after a Pest test expects; none of any other member call */
    private function pestTest(Call $call): array
    {
        return AssertionTable::pestTest($call) instanceof AssertionKind
            ? [Assertion::of(sprintf(self::PEST, $call->name()), AssertionKind::Value, AssertionStyle::Pest)]
            : [];
    }

    private function phpUnit(Call $call): Assertion|Unclassified
    {
        $kind = AssertionTable::phpUnit($call);

        return $kind instanceof AssertionKind ? Assertion::of($call->name(), $kind, AssertionStyle::PhpUnit) : $kind;
    }

    /**
     * Each call chained after an `expect()` that closes at an index: an
     * expectation, a change of subject, which asserts nothing, or a call the
     * table does not hold. A `->not` negates the expectation after it.
     *
     * @return list<Assertion|Unclassified>
     */
    private function chained(int $closer): array
    {
        $made = [];
        $negated = false;
        $after = $closer;

        foreach (self::linkEnds($this->tokens, $closer) as $end) {
            $called = $this->tokens->is($after + self::CALL_OPENS, '(');

            foreach ($called ? $this->checked(Call::at($this->tokens, $after + 2), $negated) : [] as $assertion) {
                $made[] = $assertion;
            }

            $negated = ! $called && ($negated xor $this->tokens->text($after + 2) === self::NOT);
            $after = $end;
        }

        return $made;
    }

    /**
     * What a call chained after `expect()` checks; nothing where it changes
     * the subject.
     *
     * @return list<Assertion|Unclassified>
     */
    private function checked(Call $call, bool $negated): array
    {
        return AssertionTable::isSubject($call) ? [] : [$this->pest($call, $negated)];
    }

    private function pest(Call $call, bool $negated): Assertion|Unclassified
    {
        $kind = AssertionTable::pest($call, $negated);

        $written = sprintf($negated ? self::PEST_NEGATED : self::PEST, $call->name());

        return $kind instanceof AssertionKind ? Assertion::of($written, $kind, AssertionStyle::Pest) : $kind;
    }
}
