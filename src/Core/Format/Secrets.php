<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_filter;
use function array_values;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

use function str_replace;
use function usort;

/**
 * Values that must never reach a file or a log, such as the CI's tokens,
 * each hidden in text as `***`, the longest first, so one that holds
 * another leaves none of itself. The values of the variables the gate
 * withholds from every process are such, those long enough not to be a word
 * the text holds anyway.
 */
final readonly class Secrets
{
    /** What a secret is written as. */
    private const string HIDDEN = '***';

    /** The fewest characters a withheld variable's value has to be hidden as a secret. */
    private const int SHORTEST = 8;

    /** @param list<string> $values longest first */
    private function __construct(private array $values)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(string ...$values): self
    {
        usort($values, static fn(string $one, string $other): int => mb_strlen($other) <=> mb_strlen($one));

        return new self($values);
    }

    /** The values of the variables these withhold, each of at least the fewest characters a secret has. */
    public static function withheldIn(Variables $variables, Withheld $withheld): self
    {
        return self::of(...array_values(array_filter(
            $variables->matching($withheld->pattern()),
            static fn(string $value): bool => mb_strlen($value) >= self::SHORTEST,
        )));
    }

    /** The text, each secret in it hidden. */
    public function hidden(string $text): string
    {
        $hidden = $text;

        foreach ($this->values as $value) {
            $hidden = str_replace($value, self::HIDDEN, $hidden);
        }

        return $hidden;
    }
}
