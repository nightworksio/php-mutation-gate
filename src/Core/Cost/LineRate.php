<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * What one line of code costs to mutate under a path prefix. The empty prefix
 * is under every path; any other is under itself and the paths below it.
 */
final readonly class LineRate
{
    private function __construct(private string $prefix, private Seconds $perLine)
    {
    }

    public static function of(string $prefix, Seconds $perLine): self
    {
        return new self(rtrim($prefix, '/'), $perLine);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function perLine(): Seconds
    {
        return $this->perLine;
    }

    public function covers(Path $path): bool
    {
        return $this->prefix === ''
            || $path->value() === $this->prefix
            || str_starts_with($path->value(), sprintf('%s/', $this->prefix));
    }
}
