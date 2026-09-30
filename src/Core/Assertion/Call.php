<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_values;
use function count;
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

    /** A call of a name, with its arguments as written. */
    public static function of(string $name, string ...$arguments): self
    {
        return new self($name, array_values($arguments), keyed: false);
    }

    /** A call of a name, with its arguments as written, whose first is an array with keys, such as `['total' => 3]`. */
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

        return new self(self::nameAt($tokens, $at), $arguments === [''] ? [] : $arguments, $keyed);
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

    /** The first argument as written, in lower case, as PHP reads its constants; nothing where there is none. */
    public function first(): string
    {
        foreach ($this->arguments as $argument) {
            return mb_strtolower($argument);
        }

        return '';
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
}
