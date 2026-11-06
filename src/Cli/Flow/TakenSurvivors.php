<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Recheck\ShareOf;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/**
 * The last run's survivors a re-check takes (ADR-0020, ADR-0106), in the
 * order taken, those on changed lines first, with the units that judge
 * them and what the change reaches, and the files each job's share of them
 * holds.
 *
 * @internal
 */
final readonly class TakenSurvivors
{
    /**
     * @param array<string, list<JudgedMutant>> $byFile each file's survivors, in the order taken
     * @param array<string, Unit>               $units  every unit the plan reaches, by its path
     */
    private function __construct(
        private Survivors $survivors,
        private array $byFile,
        private array $units,
        private Reach $reach,
    ) {
    }

    /** @param array<string, Unit> $units every unit the plan reaches, by its path */
    public static function of(Survivors $survivors, array $units, Reach $reach): self
    {
        $byFile = [];

        foreach ($survivors as $survivor) {
            $byFile[$survivor->mutant()->location()->file()->value()][] = $survivor;
        }

        return new self($survivors, $byFile, $units, $reach);
    }

    public function survivors(): Survivors
    {
        return $this->survivors;
    }

    /** The files of the survivors, in the order taken. */
    public function files(): Paths
    {
        return Paths::of(...array_map(Path::of(...), array_keys($this->byFile)));
    }

    /** The files of one job's share, in the order taken. */
    public function share(ShareOf $share): Paths
    {
        $files = [];

        foreach (array_keys($this->byFile) as $place => $file) {
            $files = $share->takes($place) ? [...$files, Path::of($file)] : $files;
        }

        return Paths::of(...$files);
    }

    /** @return list<JudgedMutant> the survivors of a file, in the order taken */
    public function inFile(Path $file): array
    {
        return array_key_exists($file->value(), $this->byFile) ? $this->byFile[$file->value()] : [];
    }

    /** The unit that judges a file. */
    public function unit(Path $file): Unit
    {
        return $this->units[$file->value()];
    }

    public function reach(): Reach
    {
        return $this->reach;
    }
}
