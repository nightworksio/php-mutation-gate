<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_last;
use function explode;
use function mb_strtolower;

use PhpToken;

/**
 * The words the names a file spells end in, as written and in lower case:
 * `Money`, `App\money` and `\App\MONEY` all end in `money`, as PHP reads a
 * class's or a function's name, and only the first ends in `Money`, as PHP
 * reads a constant's; and where each name ending in a word in lower case
 * stands.
 *
 * @internal
 */
final readonly class NameWords
{
    /**
     * @param array<string, true>      $words  every word, as written and in lower case
     * @param array<string, list<int>> $places where each name ending in each word in lower case stands, in order
     */
    private function __construct(private array $words, private array $places)
    {
    }

    /** @param list<PhpToken> $tokens a file's significant tokens */
    public static function of(array $tokens): self
    {
        $words = [];
        $places = [];

        $names = array_filter($tokens, static fn(PhpToken $token): bool => $token->is(Names::TOKENS));

        foreach ($names as $at => $token) {
            $last = array_last(explode('\\', $token->text));
            $lower = mb_strtolower($last);
            $words += [$last => true, $lower => true];
            $places[$lower][] = $at;
        }

        return new self($words, $places);
    }

    /**
     * Where each name that ends in this word in lower case stands, in order.
     *
     * @return list<int>
     */
    public function placesOf(string $lower): array
    {
        return array_key_exists($lower, $this->places) ? $this->places[$lower] : [];
    }

    /** Whether a name the file spells ends in this word. */
    public function has(string $word): bool
    {
        return array_key_exists($word, $this->words);
    }

    /**
     * Every word, each once.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->words);
    }
}
