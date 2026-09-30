<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function ltrim;
use function mb_strrpos;
use function mb_strtolower;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Php\Tokens;

/**
 * A call as a test writes it: the name it calls, the last segment of a
 * qualified one, and each argument as written. The table reads what an
 * assertion checks from these (ADR-0025, decision 5).
 */
final readonly class Call
{
    /** @param list<string> $arguments */
    private function __construct(private string $name, private array $arguments, private bool $keyed)
    {
    }

    /** A call of a name, with its arguments as written, spelt out rather than read from tokens as `at()` reads one. */
    public static function of(string $name, string ...$arguments): self
    {
        return new self($name, array_values($arguments), keyed: false);
    }

    /** The same, whose first argument is an array with keys, such as `['total' => 3]`. */
    public static function keyed(string $name, string ...$arguments): self
    {
        return new self($name, array_values($arguments), keyed: true);
    }

    /** The call whose name stands at an index, before its bracket, as the tokens write it. */
    public static function at(Tokens $tokens, int $at): self
    {
        $opener = $at + 1;
        $closer = $tokens->closing($opener);
        $arguments = [];
        $from = $opener + 1;

        for ($token = $from; $token <= $closer; ++$token) {
            if ($token === $closer || ($tokens->is($token, ',') && $tokens->enclosing($token) === $opener)) {
                $arguments[] = $tokens->spelt($from, $token);
                $from = $token + 1;
            }
        }

        $keyed = self::isKeyed($tokens, $opener + 1);
        $written = $arguments[count($arguments) - 1] === '' ? array_slice($arguments, 0, -1) : $arguments;

        return new self(self::nameAt($tokens, $at), $written, $keyed);
    }

    /** The name at an index as a call names it: the last segment of a qualified name. */
    public static function nameAt(Tokens $tokens, int $at): string
    {
        $text = $tokens->text($at);
        $slash = mb_strrpos($text, '\\');

        return $slash === false ? $text : mb_substr($text, $slash + 1);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The first argument as PHP reads a constant there: in lower case, unqualified; nothing where there is none. */
    public function first(): string
    {
        return $this->constantAt(0);
    }

    /** The second argument, read as the first is. */
    public function second(): string
    {
        return $this->constantAt(1);
    }

    public function arguments(): int
    {
        return count($this->arguments);
    }

    /** Whether the first argument is an array with keys, such as `['total' => 3]`. */
    public function isFirstKeyed(): bool
    {
        return $this->keyed;
    }

    /** Whether an array opens at an index and holds a key. */
    private static function isKeyed(Tokens $tokens, int $at): bool
    {
        if (! $tokens->is($at, '[')) {
            return false;
        }

        for ($token = $at + 1; $token < $tokens->closing($at); ++$token) {
            if ($tokens->is($token, T_DOUBLE_ARROW) && $tokens->enclosing($token) === $at) {
                return true;
            }
        }

        return false;
    }

    /** An argument as PHP reads a constant written there; nothing where there is none. */
    private function constantAt(int $at): string
    {
        return array_key_exists($at, $this->arguments) ? ltrim(mb_strtolower($this->arguments[$at]), '\\') : '';
    }
}
