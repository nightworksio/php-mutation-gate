<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Unit;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What a verdict and a proof are about: one source file of a tree, judged by
 * the whole suite, or a path that the tests holding it judge alone.
 */
final readonly class Unit
{
    private function __construct(private Path $path, private WholeSuite|Group|Filter $judgedBy) {}

    public static function file(Path $path): self
    {
        return new self($path, WholeSuite::tests());
    }

    public static function held(Path $path, Group|Filter $by): self
    {
        return new self($path, $by);
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function judgedBy(): WholeSuite|Group|Filter
    {
        return $this->judgedBy;
    }

    public function isHeld(): bool
    {
        return ! $this->judgedBy instanceof WholeSuite;
    }
}
