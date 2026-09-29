<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_filter;
use function explode;
use function implode;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * A path as the repository spells it: relative to its root, separated by `/`,
 * with no `.` segment and no trailing separator. The root itself is `.`.
 */
final readonly class Path
{
    private const string ROOT = '.';

    private function __construct(private string $value)
    {
    }

    public static function of(string $path): self
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn(string $segment): bool => $segment !== '' && $segment !== self::ROOT,
        );
        $normalised = implode('/', $segments);

        return new self(match (true) {
            $normalised === '' => self::ROOT,
            str_starts_with($path, '/') => sprintf('/%s', $normalised),
            default => $normalised,
        });
    }

    public static function root(): self
    {
        return new self(self::ROOT);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
