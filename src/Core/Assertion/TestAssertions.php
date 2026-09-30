<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_filter;
use function array_values;
use function mb_strtolower;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Tokens;
use PhpToken;

use function sprintf;
use function stripslashes;

/**
 * The assertions of each test a file holds, read from its tokens: a PHPUnit
 * method by its name, or a Pest `it` or `test` by its description, with the
 * calls chained after it. A test the file does not hold is not assessed
 * (ADR-0025, decision 5).
 */
final readonly class TestAssertions
{
    /** Pest's functions that declare a test, and what each puts before its description. */
    private const array DECLARING = ['it' => 'it ', 'test' => ''];

    private function __construct(private Tokens $tokens)
    {
    }

    public static function in(Contents $file): self
    {
        return new self(Tokens::of(array_values(array_filter(
            PhpToken::tokenize($file->text()),
            static fn(PhpToken $token): bool => ! $token->isIgnorable(),
        ))));
    }

    /** The assertions of the test described so: a method of that name, or a Pest test of that description. */
    public function of(string $description): Assertions
    {
        [$from, $to] = $this->method($description);
        [$from, $to] = $from === Tokens::NONE ? $this->pestTest($description) : [$from, $to];

        return $from === Tokens::NONE
            ? Assertions::notAssessed()
            : AssertionScan::between($this->tokens, $from, $to);
    }

    /** @return array{int, int} where the body of the method of this name opens and closes; nowhere without one */
    private function method(string $name): array
    {
        foreach ($this->tokens->indicesOf(T_FUNCTION) as $at) {
            $named = $this->tokens->is($at + 1, '&') ? $at + 2 : $at + 1;

            if ($this->tokens->is($named, T_STRING) && $this->tokens->text($named) === $name) {
                $opener = $this->nextBrace($named);

                return [$opener, $opener === Tokens::NONE ? Tokens::NONE : $this->tokens->closing($opener)];
            }
        }

        return [Tokens::NONE, Tokens::NONE];
    }

    /** @return array{int, int} where the Pest test of this description, with its chain, opens and ends */
    private function pestTest(string $description): array
    {
        foreach ($this->tokens->indicesOf(T_STRING) as $at) {
            if ($this->declared($at) === $description) {
                return [$at + 1, AssertionScan::chainEnd($this->tokens, $this->tokens->closing($at + 1))];
            }
        }

        return [Tokens::NONE, Tokens::NONE];
    }

    /** The description a call at this index declares a Pest test with; nothing where it declares none. */
    private function declared(int $at): string
    {
        $function = mb_strtolower($this->tokens->text($at));
        $declaring = ! AssertionScan::isMember($this->tokens, $at)
            && $this->tokens->is($at + 1, '(')
            && $this->tokens->is($at + 2, T_CONSTANT_ENCAPSED_STRING);

        return match (true) {
            ! $declaring => '',
            $function === 'it', $function === 'test' => sprintf(
                '%s%s',
                self::DECLARING[$function],
                stripslashes(mb_substr($this->tokens->text($at + 2), 1, -1)),
            ),
            default => '',
        };
    }

    /** The `{` that opens the body declared after an index; nowhere where a `;` ends it bodiless first. */
    private function nextBrace(int $from): int
    {
        for ($at = $from; $at < $this->tokens->count() && ! $this->tokens->is($at, ';'); ++$at) {
            if ($this->tokens->is($at, '{')) {
                return $at;
            }
        }

        return Tokens::NONE;
    }
}
