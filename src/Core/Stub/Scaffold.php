<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Names;

use function sprintf;

/**
 * The fixed assertion scaffold a stub offers for one mutator family, in a
 * test's style, on the call to the function around the mutant (ADR-0015,
 * decision 4): two cases for a boundary, the value a function returns, the
 * effect of a removed call, the exception a test expects, and otherwise one
 * assertion on the function's result.
 */
final readonly class Scaffold
{
    private const string BOUNDARY = 'Call it at the boundary, then one step past it:';

    private const string AT = 'at the boundary';

    private const string PAST = 'one step past it';

    private const string RETURNED = 'Assert on what it returns:';

    private const string REMOVED = 'Assert on what %s does, which every test passes without:';

    private const string THROWN = 'Expect the exception it throws:';

    private const string RESULT = 'Assert on its result:';

    private const string EXPECTED = 'expected';

    /** What a scaffold calls where the mutant is in no named function. */
    private const string UNCALLED = '/* the code it changes */';

    /** What a scaffold asserts on where a removed call's effect is the project's to name. */
    private const string EFFECT = '/* what it changes */';

    /** What a scaffold expects where the diff names no exception. */
    private const string EXCEPTION = '/* the exception */';

    private const string PEST = 'expect(%s)->toBe(/* %s */);';

    private const string PHPUNIT = '$this->assertSame(/* %s */, %s);';

    /**
     * The scaffold's lines for a mutant, in a style, as a stub writes them.
     *
     * @return list<string>
     */
    public static function of(Mutant $mutant, Enclosing|Nameless $function, AssertionStyle $style): array
    {
        $call = $function instanceof Enclosing ? $function->call() : self::UNCALLED;
        $change = Change::of($mutant->mutation()->diff());

        return match ($mutant->mutation()->family()) {
            MutatorFamily::Boundary => [
                self::BOUNDARY,
                self::asserted($style, $call, self::AT),
                self::asserted($style, $call, self::PAST),
            ],
            MutatorFamily::ReturnValue => [self::RETURNED, self::asserted($style, $call, self::EXPECTED)],
            MutatorFamily::RemovedCall => [
                sprintf(self::REMOVED, self::callee($change)),
                self::asserted($style, self::EFFECT, self::EXPECTED),
            ],
            MutatorFamily::Exception => [self::THROWN, ...self::thrown($style, $call, self::exception($change))],
            MutatorFamily::Condition,
            MutatorFamily::Logical,
            MutatorFamily::Arithmetic,
            MutatorFamily::Literal,
            MutatorFamily::Collection,
            MutatorFamily::Unwrap,
            MutatorFamily::Visibility,
            MutatorFamily::None,
            MutatorFamily::Unknown => [self::RESULT, self::asserted($style, $call, self::EXPECTED)],
        };
    }

    /** An assertion of a value on a subject, in a style, the value left as a placeholder that says what it is. */
    private static function asserted(AssertionStyle $style, string $subject, string $expected): string
    {
        return $style === AssertionStyle::Pest
            ? sprintf(self::PEST, $subject, $expected)
            : sprintf(self::PHPUNIT, $expected, $subject);
    }

    /**
     * The expectation that a call throws an exception, in a style.
     *
     * @return list<string>
     */
    private static function thrown(AssertionStyle $style, string $call, string $exception): array
    {
        return $style === AssertionStyle::Pest
            ? [sprintf('expect(fn () => %s)->toThrow(%s);', $call, $exception)]
            : [sprintf('$this->expectException(%s);', $exception), sprintf('%s;', $call)];
    }

    /** The call the mutant removed, as `save()`; the whole removed statement where it names no call. */
    private static function callee(Change $change): string
    {
        $called = $change->call();

        return $called instanceof Nameless ? sprintf('`%s`', $change->removed()) : sprintf('%s()', $called);
    }

    /** The class of the exception the removed code throws, as `InvalidArgumentException::class`. */
    private static function exception(Change $change): string
    {
        $tokens = $change->removedTokens();
        $class = self::EXCEPTION;

        foreach ($tokens->indicesOf(T_NEW) as $new) {
            $named = $class === self::EXCEPTION && $tokens->is($new + 1, ...Names::UNRELATIVE);
            $class = $named ? sprintf('%s::class', $tokens->text($new + 1)) : $class;
        }

        return $class;
    }
}
