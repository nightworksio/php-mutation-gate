<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_key_exists;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;

/**
 * A pattern over paths as the repository spells them, matched against the
 * whole path: `*` stands for any run of characters within one segment, `?`
 * for one character of a segment, and `**` for any number of segments.
 */
final readonly class Glob
{
    /** What each wildcard stands for, as a regular expression. */
    private const array WILDCARDS = [
        '**/' => '(?:.*/)?',
        '**' => '.*',
        '*' => '[^/]*',
        '?' => '[^/]',
    ];

    /** A wildcard, or a run of characters that holds none. */
    private const string PIECE = '#\*\*/|\*\*|\*|\?|[^*?]+#u';

    private function __construct(private string $pattern, private string $expression)
    {
    }

    public static function of(string $pattern): self
    {
        preg_match_all(self::PIECE, $pattern, $pieces);
        $expression = '';

        foreach ($pieces[0] as $piece) {
            $expression = sprintf(
                '%s%s',
                $expression,
                array_key_exists($piece, self::WILDCARDS) ? self::WILDCARDS[$piece] : preg_quote($piece, '#'),
            );
        }

        return new self($pattern, sprintf('#^%s$#u', $expression));
    }

    /** The pattern as it is written: `src/Legacy/**`. */
    public function value(): string
    {
        return $this->pattern;
    }

    public function matches(Path $path): bool
    {
        return preg_match($this->expression, $path->value()) === 1;
    }
}
