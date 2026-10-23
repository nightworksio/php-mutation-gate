<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\SourcePin;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Names;

use function sprintf;

/**
 * The fixed assertion scaffold a stub offers for one mutator family, in a
 * test's style, on the call to the function around the mutant, which a spec
 * makes on the object it describes (ADR-0015):
 * two cases for a boundary, the value a function returns, the effect of a
 * removed call, the exception a test expects, and otherwise one assertion
 * on the function's result; and, for a mutant only a test that reads the
 * source kills, that assertion then a read of the function's file.
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

    /** What a spec calls, on the object it describes, where the mutant is in no named method. */
    private const string UNDESCRIBED = '$this->/* the method that runs it */()';

    /** What a spec matches on, on the object it describes, where a removed call's effect is the project's to name. */
    private const string SHOWN = '$this->/* the method that shows what it changes */()';

    /** What a scaffold expects where the diff names no exception. */
    private const string EXCEPTION = '/* the exception */';

    private const string READ = 'Run it, then read the file it is in, which the mutant changes:';

    /** What a scaffold reads where the mutant is in no named function. */
    private const string UNREFLECTED = '/* the file it changes */';

    private const string PEST_READ = 'expect(file_get_contents(%s))->toContain(%s);';

    private const string PHPSPEC_READ
        = "if (! str_contains((string) file_get_contents(%s), %s)) { throw new %s('%s'); }";

    /** Why a spec that reads the source fails, where the source no longer holds what it reads. */
    private const string UNREAD = 'The file no longer holds it.';

    private const string PHPUNIT_READ = '$this->assertStringContainsString(%s, (string) file_get_contents(%s));';

    private const string PEST = 'expect(%s)->toBe(/* %s */);';

    private const string PHPUNIT = '$this->assertSame(/* %s */, %s);';

    private const string PHPSPEC = '%s->shouldReturn(/* %s */);';

    /** A spec's expectation that the object it describes throws, during a call. */
    private const string SHOULD_THROW = '$this->shouldThrow(%s)->%s;';

    /** What a spec expects to throw during, where the mutant is in no named function. */
    private const string UNTHROWN = 'during(/* the method that runs it */)';

    /**
     * The scaffold's lines for a mutant, in a style, as a stub writes them.
     *
     * @return list<string>
     */
    public static function of(Mutant $mutant, Enclosing|Nameless $function, AssertionStyle $style): array
    {
        $call = self::call($function, $style);
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
                self::asserted($style, $style === AssertionStyle::PhpSpec ? self::SHOWN : self::EFFECT, self::EXPECTED),
            ],
            MutatorFamily::Exception => [self::THROWN, ...self::thrown($style, $function, self::exception($change))],
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

    /**
     * The scaffold's lines for a mutant only a test that reads the source
     * kills (ADR-0021): a call that covers its code, then the
     * assertion that its file still holds what the pin names.
     *
     * @return list<string>
     */
    public static function pinned(SourcePin $pin, Enclosing|Nameless $function, AssertionStyle $style): array
    {
        $call = self::call($function, $style);
        $file = $function instanceof Enclosing
            ? sprintf('(%s)->getFileName()', $function->reflected())
            : self::UNREFLECTED;
        $expected = PhpCalls::literal($pin->text());

        return [
            self::READ,
            self::asserted($style, $call, self::EXPECTED),
            match ($style) {
                AssertionStyle::Pest => sprintf(self::PEST_READ, $file, $expected),
                AssertionStyle::PhpUnit => sprintf(self::PHPUNIT_READ, $expected, $file),
                AssertionStyle::PhpSpec => sprintf(
                    self::PHPSPEC_READ,
                    $file,
                    $expected,
                    AssertionStyle::FAILURE,
                    self::UNREAD,
                ),
            },
        ];
    }

    /** The call a test in a style makes to the function around the mutant; a placeholder where it is in none. */
    private static function call(Enclosing|Nameless $function, AssertionStyle $style): string
    {
        $spec = $style === AssertionStyle::PhpSpec;

        return match (true) {
            $function instanceof Nameless => $spec ? self::UNDESCRIBED : self::UNCALLED,
            $spec => $function->described(),
            default => $function->call(),
        };
    }

    /** An assertion of a value on a subject, in a style, the value left as a placeholder that says what it is. */
    private static function asserted(AssertionStyle $style, string $subject, string $expected): string
    {
        return match ($style) {
            AssertionStyle::Pest => sprintf(self::PEST, $subject, $expected),
            AssertionStyle::PhpUnit => sprintf(self::PHPUNIT, $expected, $subject),
            AssertionStyle::PhpSpec => sprintf(self::PHPSPEC, $subject, $expected),
        };
    }

    /**
     * The expectation that the function around the mutant throws an
     * exception, in a style; a spec constructs the object it describes first
     * where that function is its constructor.
     *
     * @return list<string>
     */
    private static function thrown(AssertionStyle $style, Enclosing|Nameless $function, string $exception): array
    {
        $call = self::call($function, $style);

        return match ($style) {
            AssertionStyle::Pest => [sprintf('expect(fn () => %s)->toThrow(%s);', $call, $exception)],
            AssertionStyle::PhpUnit => [sprintf('$this->expectException(%s);', $exception), sprintf('%s;', $call)],
            AssertionStyle::PhpSpec => self::shouldThrow($function, $exception),
        };
    }

    /**
     * A spec's expectation that the object it describes throws an exception
     * during the function around the mutant.
     *
     * @return list<string>
     */
    private static function shouldThrow(Enclosing|Nameless $function, string $exception): array
    {
        $during = $function instanceof Enclosing ? $function->during() : self::UNTHROWN;
        $expected = sprintf(self::SHOULD_THROW, $exception, $during);

        return $function instanceof Enclosing && $function->constructs()
            ? [sprintf('%s;', $function->described()), $expected]
            : [$expected];
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
