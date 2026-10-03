<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_values;
use function explode;
use function mb_strtolower;
use function preg_match_all;
use function str_replace;

/**
 * The words some text spells, each lower-cased and held once: every run of
 * letters, digits and underscores, and every run of those joined by hyphens,
 * read joined up as well as word by word, so that `exchange-rates` spells
 * `exchangerates`, `exchange` and `rates`.
 */
final readonly class Words
{
    /** The tokens that carry text rather than code. */
    public const array TOKENS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];

    /** A word, or a run of words joined by hyphens. */
    private const string RUNS = '/[a-z0-9_]+(?:-[a-z0-9_]+)*/u';

    /** @param array<string, string> $words each word, by itself */
    private function __construct(private array $words)
    {
    }

    public static function in(string $text): self
    {
        preg_match_all(self::RUNS, mb_strtolower($text), $found);
        $words = [];

        foreach ($found[0] as $run) {
            $joined = str_replace('-', '', $run);
            $words[$joined] = $joined;

            foreach (explode('-', $run) as $word) {
                $words[$word] = $word;
            }
        }

        return new self($words);
    }

    /** @return list<string> each word, in lower case, in the order the text first spells it */
    public function all(): array
    {
        return array_values($this->words);
    }
}
