<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_key_exists;
use function max;
use function mb_strtolower;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\Tokens;

use function sprintf;
use function stripslashes;

/**
 * The assertions of each test a file holds, read from its tokens once: a
 * PHPUnit method by its name, or a Pest `it` or `test` by its description,
 * with the calls chained after it. A test inside a `describe()` is described
 * by it, so a bare description does not find it. A test the file does not
 * hold is not assessed (ADR-0025, decision 5).
 */
final readonly class TestAssertions
{
    /** Pest's function that groups tests under a description of its own. */
    public const string DESCRIBE = 'describe';
    /** Pest's functions that declare a test, and what each puts before its description. */
    private const array DECLARING = ['it' => 'it ', 'test' => ''];

    /**
     * @param array<string, Assertions> $methods each method's assertions, by its name in lower case
     * @param array<string, Assertions> $pest    each Pest test's assertions, by its description
     */
    private function __construct(private array $methods, private array $pest)
    {
    }

    /** The tests a file holds, where a test may call the helpers the file declares and these. */
    public static function in(Contents $file, Helpers $shared): self
    {
        $tokens = Tokens::in($file);
        $helpers = Helpers::declaredIn($tokens)->and($shared);

        return new self(self::methods($tokens, $helpers), self::pestTests($tokens, $helpers));
    }

    /** The assertions of the test described so: a method of that name, or a Pest test of that description. */
    public function of(string $description): Assertions
    {
        $method = mb_strtolower($description);

        return match (true) {
            array_key_exists($method, $this->methods) => $this->methods[$method],
            array_key_exists($description, $this->pest) => $this->pest[$description],
            default => Assertions::notAssessed(),
        };
    }

    /**
     * @return array<string, Assertions> each method's assertions, the first of a name, by its name in lower case
     */
    private static function methods(Tokens $tokens, Helpers $helpers): array
    {
        $methods = [];

        foreach ($tokens->indicesOf(T_FUNCTION) as $at) {
            $named = $tokens->functionName($at);
            $name = $named === Tokens::NONE ? '' : mb_strtolower($tokens->text($named));

            if ($name !== '' && ! array_key_exists($name, $methods)) {
                $methods[$name] = self::bodyAfter($tokens, $helpers, $named);
            }
        }

        return $methods;
    }

    /**
     * @return array<string, Assertions> each Pest test's assertions outside a `describe()`, by its description
     */
    private static function pestTests(Tokens $tokens, Helpers $helpers): array
    {
        $tests = [];
        $described = Tokens::NONE;

        foreach ($tokens->indicesOf(...Names::TOKENS) as $at) {
            $function = mb_strtolower(Call::nameAt($tokens, $at));
            $description = $at > $described ? self::declared($tokens, $at, $function) : '';
            $described = $function === self::DESCRIBE && self::isDeclaring($tokens, $at)
                ? max($described, $tokens->closing($at + 1))
                : $described;

            if ($description !== '' && ! array_key_exists($description, $tests)) {
                $end = AssertionScan::chainEnd($tokens, $tokens->closing($at + 1));
                $tests[$description] = AssertionScan::between($tokens, $helpers, $at + 1, $end);
            }
        }

        return $tests;
    }

    /** Whether a call at an index declares something by a description, as `it('…', …)` does. */
    private static function isDeclaring(Tokens $tokens, int $at): bool
    {
        return ! AssertionScan::isMember($tokens, $at)
            && $tokens->is($at + 1, '(')
            && $tokens->is($at + 2, T_CONSTANT_ENCAPSED_STRING);
    }

    /** The description a call of a function at an index declares a Pest test with; nothing where it declares none. */
    private static function declared(Tokens $tokens, int $at, string $function): string
    {
        return array_key_exists($function, self::DECLARING) && self::isDeclaring($tokens, $at)
            ? sprintf('%s%s', self::DECLARING[$function], stripslashes(mb_substr($tokens->text($at + 2), 1, -1)))
            : '';
    }

    /**
     * The assertions of the body declared after an index; none assessed of a
     * declaration a `;` ends bodiless.
     *
     */
    private static function bodyAfter(Tokens $tokens, Helpers $helpers, int $from): Assertions
    {
        $opener = self::nextBrace($tokens, $from);

        return $opener === Tokens::NONE
            ? Assertions::notAssessed()
            : AssertionScan::between($tokens, $helpers, $opener, $tokens->closing($opener));
    }

    /** The `{` that opens the body declared after an index; nowhere where a `;` ends it bodiless first. */
    private static function nextBrace(Tokens $tokens, int $from): int
    {
        for ($at = $from; $at < $tokens->count() && ! $tokens->is($at, ';'); ++$at) {
            if ($tokens->is($at, '{')) {
                return $at;
            }
        }

        return Tokens::NONE;
    }
}
