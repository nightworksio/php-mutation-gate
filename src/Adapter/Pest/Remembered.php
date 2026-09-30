<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Groups;

/**
 * What one Pest runner learns once for every run it starts in a process: the
 * groups its suite lists, the PHP it starts, whether the vendor is patched,
 * each map another job handed over, and the maps it has written again for
 * Pest. Each is slow to learn, from a process started for it or a map that
 * can reach hundreds of megabytes, and none changes while the gate runs.
 */
final class Remembered
{
    /** @var array<string, Groups|CannotJudge> by what the listing withheld */
    private array $groups = [];

    /** @var array<string, Platform|CannotJudge> by what the PHP described withheld */
    private array $platforms = [];

    /** @var list<bool> whether pest-plugin-mutate is patched, once asked */
    private array $patched = [];

    /** @var array<string, CoverageMap|CannotJudge> by the directory it was handed over in */
    private array $maps = [];

    /** @var array<string, true> the files a map has been written to */
    private array $written = [];

    /** @param Closure(): (Groups|CannotJudge) $listing */
    public function groups(Withheld $withheld, Closure $listing): Groups|CannotJudge
    {
        $key = $withheld->pattern();

        if (! array_key_exists($key, $this->groups)) {
            $this->groups[$key] = $listing();
        }

        return $this->groups[$key];
    }

    /** @param Closure(): (Platform|CannotJudge) $describing */
    public function platform(Withheld $withheld, Closure $describing): Platform|CannotJudge
    {
        $key = $withheld->pattern();

        if (! array_key_exists($key, $this->platforms)) {
            $this->platforms[$key] = $describing();
        }

        return $this->platforms[$key];
    }

    /** @param Closure(): bool $checking */
    public function patched(Closure $checking): bool
    {
        if ($this->patched === []) {
            $this->patched = [$checking()];
        }

        return $this->patched[0];
    }

    /** @param Closure(): (CoverageMap|CannotJudge) $reading */
    public function map(Path $directory, Closure $reading): CoverageMap|CannotJudge
    {
        $key = $directory->value();

        if (! array_key_exists($key, $this->maps)) {
            $this->maps[$key] = $reading();
        }

        return $this->maps[$key];
    }

    /**
     * Writes a file once: a map written again for Pest is the same map every
     * time, so later runs find it where the first left it.
     *
     * @param Closure(): void $writing
     */
    public function writeOnce(string $file, Closure $writing): void
    {
        if (! array_key_exists($file, $this->written)) {
            $writing();
            $this->written[$file] = true;
        }
    }
}
