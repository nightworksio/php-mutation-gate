<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_find_key;
use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function implode;
use function is_int;

use const PHP_INT_MAX;

use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;
use function str_contains;

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

    /** A segment that holds a wildcard. */
    private const string WILD = '#[*?]#u';

    /** The wildcard that crosses directories. */
    private const string DEEP = '**';

    /**
     * @param string $pattern    the pattern as it is written
     * @param string $expression the pattern, as a regular expression over a whole path
     */
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

    /** The directory every path it matches is inside: its segments before the first that holds a wildcard. */
    public function base(): Path
    {
        $segments = explode('/', $this->pattern);
        $wild = array_find_key($segments, static fn(string $segment): bool => preg_match(self::WILD, $segment) === 1);
        $literal = array_slice($segments, 0, is_int($wild) ? $wild : count($segments));

        return $literal === [] ? Path::root() : Path::of(implode('/', $literal));
    }

    /** How many segments a path it matches can have at most; with `**`, any number. */
    public function depth(): int
    {
        return str_contains($this->pattern, self::DEEP) ? PHP_INT_MAX : count(explode('/', $this->pattern));
    }

    public function matches(Path $path): bool
    {
        return preg_match($this->expression, $path->value()) === 1;
    }
}
