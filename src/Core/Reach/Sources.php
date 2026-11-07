<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * What the reach rules read: files as they are on disk, changed files as
 * they were at the base, and the changed files that decide how the gate runs
 * as they did at the base.
 *
 * @internal
 */
final readonly class Sources
{
    /**
     * @param ByPath<Contents|Missing> $now    each file as it is on disk
     * @param ByPath<Contents|Missing> $before each changed file as it was at the base
     * @param Paths                    $alike  each changed file that decides how the gate runs and decides it as it
     *                                         did at the base
     * @param ByPath<Listed<Glob>>     $moved  each such config's globs whose files run otherwise than at the base,
     *                                         as the `paths` entries it moved match them (ADR-0074)
     * @param ByPath<LockLoads>        $loads  each such lock's moved packages, and the files of the tests that
     *                                         loaded them (ADR-0113)
     */
    private function __construct(
        private ByPath $now,
        private ByPath $before,
        private Paths $alike,
        private ByPath $moved,
        private ByPath $loads,
    ) {
    }

    public static function none(): self
    {
        return new self(ByPath::none(), ByPath::none(), Paths::none(), ByPath::none(), ByPath::none());
    }

    /**
     * Files as a change source reads them together: as they are on disk, and
     * the changed ones as they were at the base.
     *
     * @param ByPath<Contents|Missing> $now
     * @param ByPath<Contents|Missing> $before
     */
    public static function of(ByPath $now, ByPath $before): self
    {
        return new self($now, $before, Paths::none(), ByPath::none(), ByPath::none());
    }

    /** These, with what a file holds on disk. */
    public function withNow(Path $path, Contents $contents): self
    {
        return new self($this->now->with($path, $contents), $this->before, $this->alike, $this->moved, $this->loads);
    }

    /** These, with what a changed file held at the base. */
    public function withBefore(Path $path, Contents $contents): self
    {
        return new self($this->now, $this->before->with($path, $contents), $this->alike, $this->moved, $this->loads);
    }

    /**
     * These, where a changed file that decides how the gate runs decides it
     * as it did at the base, such as a config whose change leaves every
     * setting that affects results as it was (ADR-0005).
     */
    public function decidingAlike(Path $path): self
    {
        return new self($this->now, $this->before, $this->alike->with($path), $this->moved, $this->loads);
    }

    /**
     * These, where a config file deciding alike moved `paths` entries, so the files these globs match run
     * otherwise than at the base (ADR-0074).
     */
    public function movingRules(Path $path, Glob $glob, Glob ...$more): self
    {
        $moved = $this->moved->with($path, Listed::of($glob, ...$more));

        return new self($this->now, $this->before, $this->alike, $moved, $this->loads);
    }

    /**
     * These, where a lock deciding alike moved packages that some tests loaded (ADR-0113).
     */
    public function movingPackages(Path $path, LockLoads $moved): self
    {
        $loads = $this->loads->with($path, $moved);

        return new self($this->now, $this->before, $this->alike, $this->moved, $loads);
    }

    /** The packages a lock's change moved, and the files of the tests that loaded them; none where it says none. */
    public function loadsMoved(Path $path): LockLoads|NotGiven
    {
        return $this->loads->at($path, NotGiven::value());
    }

    /**
     * The globs whose files a config file's change makes run otherwise, as the `paths` entries it moved match
     * them; none where it moved none.
     *
     * @return list<Glob>
     */
    public function movedRules(Path $path): array
    {
        return [...$this->moved->at($path, Listed::of())];
    }

    /**
     * Whether a change to a file that decides how the gate runs leaves it
     * deciding as it did at the base: a change to the file in its place, never
     * one that renames, moves, adds or deletes it, which decides anew where
     * the gate looks (ADR-0005).
     */
    public function decidesAlike(Change $change): bool
    {
        return $change->kind() === ChangeKind::Modified && $this->alike->has($change->path());
    }

    /**
     * Whether a change to a CI definition that runs the gate leaves it running
     * the gate as it did at the base: a change to the file in its place that
     * moved only lines no runner reads (AsItRuns).
     */
    public function runsAlike(Change $change): bool
    {
        $before = $this->before($change->path());
        $after = $this->now($change->path());

        return $change->kind() === ChangeKind::Modified
            && $before instanceof Contents
            && $after instanceof Contents
            && AsItRuns::alike($before, $after);
    }

    public function now(Path $path): Contents|Missing
    {
        return $this->now->at($path, Missing::at($path));
    }

    public function before(Path $path): Contents|Missing
    {
        return $this->before->at($path, Missing::at($path));
    }

    /**
     * Every PHP file on disk, read, in the order the files came.
     *
     * @return ByPath<PhpFile>
     */
    public function php(): ByPath
    {
        $read = [];
        $paths = [];

        foreach ($this->now as $path => $contents) {
            if ($contents instanceof Contents && $path->isPhp()) {
                $read[$path->value()] = PhpFile::read($contents);
                $paths[] = $path;
            }
        }

        return ByPath::mapping(Paths::of(...$paths), static fn(Path $path): PhpFile => $read[$path->value()]);
    }
}
