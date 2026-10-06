<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * What the reach rules read: files as they are on disk, changed files as
 * they were at the base, and the changed files that decide how the gate runs
 * as they did at the base.
 */
final readonly class Sources
{
    /**
     * @param ByPath<Contents|Missing> $now    each file as it is on disk
     * @param ByPath<Contents|Missing> $before each changed file as it was at the base
     * @param Paths                    $alike  each changed file that decides how the gate runs and decides it as it
     *                                         did at the base
     */
    private function __construct(private ByPath $now, private ByPath $before, private Paths $alike)
    {
    }

    public static function none(): self
    {
        return new self(ByPath::none(), ByPath::none(), Paths::none());
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
        return new self($now, $before, Paths::none());
    }

    /** These, with what a file holds on disk. */
    public function withNow(Path $path, Contents $contents): self
    {
        return new self($this->now->with($path, $contents), $this->before, $this->alike);
    }

    /** These, with what a changed file held at the base. */
    public function withBefore(Path $path, Contents $contents): self
    {
        return new self($this->now, $this->before->with($path, $contents), $this->alike);
    }

    /**
     * These, where a changed file that decides how the gate runs decides it
     * as it did at the base, such as a config whose change leaves every
     * setting that affects results as it was (ADR-0005, decision 4).
     */
    public function decidingAlike(Path $path): self
    {
        return new self($this->now, $this->before, $this->alike->with($path));
    }

    /** Whether a changed file decides how the gate runs as it did at the base. */
    public function decidesAlike(Path $path): bool
    {
        return $this->alike->has($path);
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
