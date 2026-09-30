<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_intersect_key;
use function array_key_exists;
use function array_shift;
use function count;
use function explode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * Paths a change reached, each once in the order it reached them, held so
 * that whether one is inside another is a lookup: each path by itself, and
 * each path and every directory it is inside, but the root, which holds
 * every path.
 */
final readonly class Reached
{
    /** How the root is spelt, which every path is inside. */
    private const string ROOT = '.';

    /**
     * @param array<string, true> $own   each path reached, by itself
     * @param array<string, true> $under each path reached and every directory it is inside, by itself
     */
    private function __construct(private Paths $paths, private array $own, private array $under)
    {
    }

    public static function none(): self
    {
        return new self(Paths::none(), [], []);
    }

    /** These, and more paths. */
    public function and(Paths $paths): self
    {
        $own = $this->own;
        $under = $this->under;

        foreach ($paths as $path) {
            $own[$path->value()] = true;
            $under += $this->downTo($path);
        }

        return new self(Paths::of(...$this->paths, ...$paths), $own, $under);
    }

    public function paths(): Paths
    {
        return $this->paths;
    }

    /** Whether any of these is a directory's own path, or inside it. */
    public function anyWithin(Path $directory): bool
    {
        return array_key_exists($directory->value(), $this->under)
            || ($directory->value() === self::ROOT && count($this->paths) > 0);
    }

    /** Whether a path is inside any of these, or is one. */
    public function anyAround(Path $path): bool
    {
        return array_intersect_key([self::ROOT => true] + $this->downTo($path), $this->own) !== [];
    }

    /**
     * A path and every directory it is inside, but the root, by itself.
     *
     * @return array<string, true>
     */
    private function downTo(Path $path): array
    {
        $segments = explode('/', $path->value());
        $spelt = array_shift($segments);
        $down = [$spelt => true];

        foreach ($segments as $segment) {
            $spelt = sprintf('%s/%s', $spelt, $segment);
            $down[$spelt] = true;
        }

        return $down;
    }
}
