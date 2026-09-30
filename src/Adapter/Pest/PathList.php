<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function implode;

use NightWorksIO\MutationGate\Core\File\Paths;

use function str_contains;

/** Paths as Pest's options list them: joined by commas, which none of them may hold. */
final readonly class PathList
{
    private function __construct(private Paths $paths)
    {
    }

    public static function of(Paths $paths): self
    {
        return new self($paths);
    }

    /** Whether a path holds a comma, where Pest would split it in two. */
    public function holdsAComma(): bool
    {
        foreach ($this->paths as $path) {
            if (str_contains($path->value(), ',')) {
                return true;
            }
        }

        return false;
    }

    public function joined(string $between): string
    {
        $values = [];

        foreach ($this->paths as $path) {
            $values[] = $path->value();
        }

        return implode($between, $values);
    }
}
