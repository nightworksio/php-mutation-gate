<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_any;
use function array_values;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sprintf;

/**
 * What a change reaches, with a sentence for every decision: every unit, the
 * whole of some packages, some files, some trees, the files some globs match,
 * or nothing. It also holds
 * the lines the change added or modified in each source file, which the
 * new-code floor judges.
 */
final readonly class Reach
{
    private const string SINCE_LEDGER = 'On the default branch since its ledger last passed, at %s: %s';

    /**
     * @param ByPath<Lines> $lines the changed lines of each source file
     * @param list<Glob>    $globs the globs whose files the change reaches, as `paths` entries it moved match them
     */
    private function __construct(
        private Packages $packages,
        private bool $everywhere,
        private Paths $wholly,
        private Reached $files,
        private Reached $trees,
        private ByPath $lines,
        private Reasons $reasons,
        private array $globs,
    ) {
    }

    /** A change that reaches nothing yet, in a repository of these packages. */
    public static function nothing(Packages $packages): self
    {
        return new self(
            $packages,
            everywhere: false,
            wholly: Paths::none(),
            files: Reached::none(),
            trees: Reached::none(),
            lines: ByPath::none(),
            reasons: Reasons::of(),
            globs: [],
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
            reasons: $this->reasons->with($reason->widening()),
            globs: $this->globs,
        );
    }

    /** This reach, and every unit of these packages. */
    public function wholly(Paths $packages, Reason $reason): self
    {
        return new self(
            $this->packages,
            $this->everywhere,
            Paths::of(...$this->wholly, ...$packages),
            $this->files,
            $this->trees,
            $this->lines,
            $this->reasons->with($reason->widening()),
            $this->globs,
        );
    }

    /** This reach, and the units these source files are in. */
    public function files(Paths $files, Reason $reason): self
    {
        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $this->files->and($files),
            $this->trees,
            $this->lines,
            $this->reasons->with($reason),
            $this->globs,
        );
    }

    /** This reach, and the units whose files these globs match. */
    public function matching(Glob $glob, Glob ...$more): self
    {
        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $this->files,
            $this->trees,
            $this->lines,
            $this->reasons,
            [...$this->globs, $glob, ...array_values($more)],
        );
    }

    /** This reach, and every unit of these trees. */
    public function trees(Paths $trees, Reason $reason): self
    {
        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $this->files,
            $this->trees->and($trees),
            $this->lines,
            $this->reasons->with($reason),
            $this->globs,
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
            $this->globs,
        );
    }

    /**
     * This reach, and what the default branch changed since the commit its
     * ledger last passed at reaches, each of those reasons after this one's,
     * saying so; the changed lines this one's alone (ADR-0060).
     */
    public function andSinceLedger(self $sinceLedger, Revision $ledger): self
    {
        $reasons = $this->reasons;

        foreach ($sinceLedger->reasons as $reason) {
            $said = Reason::that(sprintf(self::SINCE_LEDGER, $ledger->name(), $reason->text()));
            $reasons = $reasons->with($reason->widens() ? $said->widening() : $said);
        }

        return new self(
            $this->packages,
            $this->everywhere || $sinceLedger->everywhere,
            Paths::of(...$this->wholly, ...$sinceLedger->wholly),
            $this->files->and($sinceLedger->files->paths()),
            $this->trees->and($sinceLedger->trees->paths()),
            $this->lines,
            $reasons,
            [...$this->globs, ...$sinceLedger->globs],
        );
    }

    /** This reach, knowing which lines of a source file the change added or modified. */
    public function withLines(Path $file, Lines $lines): self
    {
        return new self(
            $this->packages,
            $this->everywhere,
            $this->wholly,
            $this->files,
            $this->trees,
            $this->lines->with($file, $lines),
            $this->reasons,
            $this->globs,
        );
    }

    /** Whether the change reaches a unit: its package whole, a tree around it, or a file of it. */
    public function reaches(Unit $unit): bool
    {
        return $this->everywhere
            || $this->wholly->has($this->packages->holding($unit->path())->path())
            || $this->files->anyWithin($unit->path())
            || $this->trees->anyAround($unit->path())
            || array_any($this->globs, static fn(Glob $glob): bool => $glob->matches($unit->path()));
    }

    /** Whether the change reaches anything in a package, which is planned only if it does. */
    public function reachesPackage(Package $package): bool
    {
        return $this->everywhere
            || $this->wholly->has($package->path())
            || $this->holdsAny($this->files->paths(), $package)
            || $this->holdsAny($this->trees->paths(), $package)
            || array_any(
                $this->globs,
                static fn(Glob $glob): bool => self::overlaps($glob->base(), $package->path()),
            );
    }

    /** The lines of a source file the change added or modified; none for a file it did not change. */
    public function changedLines(Path $file): Lines
    {
        return $this->lines->at($file, Lines::none());
    }

    /** Whether the change added or modified a line of a unit: of its file, or of a file within the path it holds. */
    public function changesLinesOf(Unit $unit): bool
    {
        foreach ($this->lines->paths() as $file) {
            if ($file->within($unit->path()) && $this->changedLines($file)->count() > 0) {
                return true;
            }
        }

        return false;
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

    /** Whether a glob's base and a package's path hold one another, so the glob can match a file of the package. */
    private static function overlaps(Path $base, Path $package): bool
    {
        return $base->within($package) || $package->within($base);
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
}
