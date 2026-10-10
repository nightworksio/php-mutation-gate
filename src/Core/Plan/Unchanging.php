<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function sprintf;

/**
 * Whether the verdict of the newest commit of a run's scope whose verdict
 * passed stands for what is there now, so a plan need run nothing: it passed
 * under the run's own check, its record says when, every path the change
 * since it touched, as it is and as it was, is no PHP file and lies outside
 * every directory of tests, none decides how the gate runs (ADR-0005,
 * decision 4, rule 1) or is the baseline, and no ignore that applied when it
 * passed has expired since. Anything else, and the plan runs as ever.
 */
final readonly class Unchanging
{
    private const string NO_TIME
        = 'The ledger does not say when %s passed, so whether an ignore expired since cannot be told.';

    private const string OTHER_CHECK = '%s passed under the check %s, not %s.';

    private const string PHP = '%s is PHP.';

    private const string TESTS = '%s is in a directory of tests.';

    private const string DECIDES = '%s decides how the gate runs.';

    private const string BASELINE = '%s is the baseline.';

    private const string EXPIRED = 'The ignore of %s has expired since %s passed.';

    private function __construct(private Layout $layout, private Packages $packages, private Path $baseline)
    {
    }

    /** Judged against the files this layout names, in these packages, with the baseline at this path. */
    public static function within(Layout $layout, Packages $packages, Path $baseline): self
    {
        return new self($layout, $packages, $baseline);
    }

    /**
     * The verdict that stands for the change since the commit that passed,
     * judged now under this check with these ignores; or why the plan runs.
     *
     * @param Listed<Ignored> $ignores
     */
    public function since(
        Passed|CannotTell $base,
        Changes|CannotTell $changes,
        string $check,
        Listed $ignores,
        DateTimeImmutable $now,
    ): Unchanged|Reason {
        if ($base instanceof CannotTell) {
            return Reason::that($base->why());
        }

        $at = $base->at();
        $standing = $at instanceof Instant
            ? $this->standing($base, $at, $changes, $check)
            : Reason::that(sprintf(self::NO_TIME, $base->commit()->name()));

        if (! $standing instanceof Unchanged) {
            return $standing;
        }

        $expired = $standing->expiredBy($ignores, $now);

        return $expired instanceof Ignored
            ? Reason::that(sprintf(self::EXPIRED, $expired->named(), $base->commit()->name()))
            : $standing;
    }

    /** The verdict of a commit that passed at an instant, standing for the change since; or why it does not. */
    private function standing(Passed $base, Instant $at, Changes|CannotTell $changes, string $check): Unchanged|Reason
    {
        $touched = $changes instanceof Changes ? $this->touched($changes) : Reason::that($changes->why());

        return match (true) {
            $base->check() !== $check => Reason::that(
                sprintf(self::OTHER_CHECK, $base->commit()->name(), $base->check(), $check),
            ),
            $touched instanceof Reason => $touched,
            default => Unchanged::since($base, $at),
        };
    }

    /** Why the first path these changes touch that matters to the gate matters; none where none does. */
    private function touched(Changes $changes): Reason|NotGiven
    {
        foreach ($changes as $change) {
            foreach ($this->pathsOf($change) as $path) {
                $why = $this->why($path);

                if ($why instanceof Reason) {
                    return $why;
                }
            }
        }

        return NotGiven::value();
    }

    /** @return list<Path> a change's path as it is and, where it was renamed, as it was */
    private function pathsOf(Change $change): array
    {
        return $change->previousPath()->equals($change->path())
            ? [$change->path()]
            : [$change->previousPath(), $change->path()];
    }

    private function why(Path $path): Reason|NotGiven
    {
        $package = $this->packages->holding($path)->path();
        $said = match (true) {
            $path->isPhp() => self::PHP,
            $this->layout->isInTests($path->relativeTo($package)) => self::TESTS,
            $this->layout->decides($path, $package),
            $this->layout->runsTheGate($path),
            $path->equals(Manifest::lockIn($package)) => self::DECIDES,
            $path->equals($this->baseline) => self::BASELINE,
            default => '',
        };

        return $said === '' ? NotGiven::value() : Reason::that(sprintf($said, $path->value()));
    }
}
