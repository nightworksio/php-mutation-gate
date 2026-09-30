<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function mb_strlen;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What one line of code costs to mutate under a path prefix. The empty prefix
 * is under every path; any other is under itself and the paths below it.
 */
final readonly class LineRate
{
    /** How `costs.secondsPerLine` writes the prefix of every path. */
    public const string EVERYWHERE = '';

    private function __construct(private Path $prefix, private Seconds $perLine)
    {
    }

    /** A rate under a prefix as `costs.secondsPerLine` writes it: the empty prefix, or the root, is every path. */
    public static function of(string $prefix, Seconds $perLine): self
    {
        return new self(Path::of($prefix), $perLine);
    }

    /** A rate for every path. */
    public static function everywhere(Seconds $perLine): self
    {
        return new self(Path::root(), $perLine);
    }

    public function prefix(): Path
    {
        return $this->prefix;
    }

    public function perLine(): Seconds
    {
        return $this->perLine;
    }

    public function covers(Path $path): bool
    {
        return $path->within($this->prefix);
    }

    /** How narrow the prefix is: every path the least, and a longer prefix the more. */
    public function narrowness(): int
    {
        return $this->isEverywhere() ? 0 : mb_strlen($this->prefix->value());
    }

    /** The prefix as `costs.secondsPerLine` writes it. */
    public function written(): string
    {
        return $this->isEverywhere() ? self::EVERYWHERE : $this->prefix->value();
    }

    private function isEverywhere(): bool
    {
        return $this->prefix->value() === Path::root()->value();
    }
}
