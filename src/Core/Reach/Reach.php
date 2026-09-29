<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * What a change reaches, with a sentence for every decision: every unit, the
 * whole of some packages, some files, some trees, or nothing. It also holds
 * the lines the change added or modified in each source file, which the
 * new-code floor judges.
 */
final readonly class Reach
{
    /** @param array<string, Lines> $lines the changed lines of each source file, by its path */
    private function __construct(
        private Packages $packages,
        private bool $everywhere,
        private Paths $wholly,
        private Paths $files,
        private Paths $trees,
        private array $lines,
        private Reasons $reasons,
    ) {
    }

    /** A change that reaches nothing yet, in a repository of these packages. */
    public static function nothing(Packages $packages): self
    {
        return new self(
            $packages,
            everywhere: false,
            wholly: Paths::none(),
            files: Paths::none(),
            trees: Paths::none(),
            lines: [],
            reasons: Reasons::of(),
        );
    }

    /** This reach, and every unit of every package. */
    public function everywhere(Reason $reason): self
    {
        return new self(
            $this->packages,
            everywhere: true,
            wholly: $this->wholly,
            files: $this->files,
            trees: $this->trees,
            lines: $this->lines,
            reasons: $this->reasons->with($reason),
        );
    }

    /** This reach, and every unit of these packages. */
    public function wholly(Paths $packages, Reason $reason): self
    {
        $wholly = $this->wholly;

        foreach ($packages as $package) {
            $wholly = $wholly->with($package);
        }

        return new self(
            $this->packages,
            $this->everywhere,
            $wholly,
            $this->files,
            $this->trees,
            $this->lines,
            $this->reasons->with($reason),
        );
    }

    /** This reach, and the units these source files are in. */
    public function files(Paths $files, Reason $reason): self
    {
        $reached = $this->files;

        foreach ($files as $file) {
            $reached = $reached->with($file);
        }

        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $reached,
            $this->trees,
            $this->lines,
            $this->reasons->with($reason),
        );
    }

    /** This reach, and every unit of these trees. */
    public function trees(Paths $trees, Reason $reason): self
    {
        $reached = $this->trees;

        foreach ($trees as $tree) {
            $reached = $reached->with($tree);
        }

        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $this->files,
            $reached,
            $this->lines,
            $this->reasons->with($reason),
        );
    }

    /** This reach, and one more decision, which reached nothing. */
    public function because(Reason $reason): self
    {
        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $this->files,
            $this->trees,
            $this->lines,
            $this->reasons->with($reason),
        );
    }

    /** This reach, knowing which lines of a source file the change added or modified. */
    public function withLines(Path $file, Lines $lines): self
    {
        $changed = $this->lines;
        $changed[$file->value()] = $lines;

        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $this->files,
            $this->trees,
            $changed,
            $this->reasons,
        );
    }

    /** Whether the change reaches a unit: its package whole, a tree around it, or a file of it. */
    public function reaches(Unit $unit): bool
    {
        return $this->everywhere
            || $this->wholly->has($this->packages->holding($unit->path())->path())
            || $this->anyWithin($this->files, $unit->path())
            || $this->anyAround($this->trees, $unit->path());
    }

    /** Whether the change reaches anything in a package, which is planned only if it does. */
    public function reachesPackage(Package $package): bool
    {
        return $this->everywhere
            || $this->wholly->has($package->path())
            || $this->holdsAny($this->files, $package)
            || $this->holdsAny($this->trees, $package);
    }

    /** The lines of a source file the change added or modified; none for a file it did not change. */
    public function changedLines(Path $file): Lines
    {
        return array_key_exists($file->value(), $this->lines) ? $this->lines[$file->value()] : Lines::none();
    }

    /** Whether the change reaches every unit of every package. */
    public function isEverywhere(): bool
    {
        return $this->everywhere;
    }

    public function reasons(): Reasons
    {
        return $this->reasons;
    }

    /** Whether any of these paths is a package's own, or inside it and in no package within it. */
    private function holdsAny(Paths $paths, Package $package): bool
    {
        foreach ($paths as $path) {
            if ($this->packages->holding($path)->path()->equals($package->path())) {
                return true;
            }
        }

        return false;
    }

    /** Whether any of these paths is a unit's own path, or inside it. */
    private function anyWithin(Paths $paths, Path $unit): bool
    {
        foreach ($paths as $path) {
            if ($path->within($unit)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a unit is inside any of these paths. */
    private function anyAround(Paths $paths, Path $unit): bool
    {
        foreach ($paths as $path) {
            if ($unit->within($path)) {
                return true;
            }
        }

        return false;
    }
}
