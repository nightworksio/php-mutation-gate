<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;

use Closure;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * What the adapter's own coverage directory holds since its last mutation
 * run began: PHPUnit's run of some tests under coverage, or a map another job
 * handed on. A run again that reads the same reads it where it is, so it runs
 * no suite under coverage a second time. Each mutation run forgets it and
 * collects its own, so coverage never outlives an edit between runs, as
 * `watch` makes. The key is the command and what it withheld; a cap on the
 * coverage run, were there one, would belong in it too.
 */
final class HeldCoverage
{
    /** @var array<string, DiskPath> the directory by what it holds: nothing yet, or one entry */
    private array $holding = [];

    /** Holds nothing, so the next run fills the directory afresh. */
    public function forget(): void
    {
        $this->holding = [];
    }

    /**
     * The directory, once PHPUnit has run this command into it, unless it
     * already holds that run.
     *
     * @param Closure(): (DiskPath|CannotJudge) $running
     */
    public function ranBy(Command $run, Closure $running): DiskPath|CannotJudge
    {
        $what = sprintf("run\n%s\n%s", implode("\n", $run->arguments()), $run->withheld()->pattern());

        return $this->held($what, $running);
    }

    /**
     * The directory, once the map another job handed on in this directory is
     * written into it, unless it already holds that map.
     *
     * @param Closure(): (DiskPath|CannotJudge) $writing
     */
    public function handedOn(Path $directory, Closure $writing): DiskPath|CannotJudge
    {
        return $this->held(sprintf("handed\n%s", $directory->value()), $writing);
    }

    /** @param Closure(): (DiskPath|CannotJudge) $filling */
    private function held(string $what, Closure $filling): DiskPath|CannotJudge
    {
        if (array_key_exists($what, $this->holding)) {
            return $this->holding[$what];
        }

        $this->holding = [];
        $filled = $filling();
        $this->holding = $filled instanceof CannotJudge ? [] : [$what => $filled];

        return $filled;
    }
}
