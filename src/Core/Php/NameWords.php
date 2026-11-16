<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

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
 * reads a constant's.
 *
 * @internal
 */
final readonly class NameWords
{
    /** @param array<string, true> $words */
    private function __construct(private array $words)
    {
    }

    /** @param list<PhpToken> $tokens a file's significant tokens */
    public static function of(array $tokens): self
    {
        $words = [];

        foreach ($tokens as $token) {
            $last = $token->is(Names::TOKENS) ? array_last(explode('\\', $token->text)) : null;
            $words += $last === null ? [] : [$last => true, mb_strtolower($last) => true];
        }

        return new self($words);
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
