<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

use function array_any;
use function array_last;
use function explode;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;

use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function trim;

/**
 * A CODEOWNERS path pattern, read as GitHub reads it (ADR-0022). A pattern
 * with a slash at its start or within it is anchored at the repository's
 * root, and one without matches at any depth. It matches a file, or every
 * file under a directory it names, and one ending in a slash only the
 * latter. A last segment of a lone `*` matches the files directly in its
 * directory and nothing deeper. Its wildcards are a glob's.
 */
final readonly class OwnerPattern
{
    /** The last segment that matches only the files directly in its directory. */
    private const string DIRECTLY = '*';

    /** @param list<Glob> $globs the globs a path matches the pattern through, any one of them */
    private function __construct(private string $pattern, private array $globs)
    {
    }

    public static function of(string $pattern): self
    {
        $body = trim($pattern, '/');
        $spelt = str_contains(rtrim($pattern, '/'), '/')
            ? $body
            : sprintf('%s/%s', Glob::DEEP, $body);
        $under = Glob::of(sprintf('%s/%s', $spelt, Glob::DEEP));

        $globs = match (true) {
            str_ends_with($pattern, '/') => [$under],
            array_last(explode('/', $body)) === self::DIRECTLY => [Glob::of($spelt)],
            default => [Glob::of($spelt), $under],
        };

        return new self($pattern, $globs);
    }

    /** The pattern as the rule writes it. */
    public function value(): string
    {
        return $this->pattern;
    }

    public function matches(Path $path): bool
    {
        return array_any($this->globs, static fn(Glob $glob): bool => $glob->matches($path));
    }
}
