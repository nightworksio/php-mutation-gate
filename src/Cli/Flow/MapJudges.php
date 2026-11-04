<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Port\Runner;

/**
 * The test files the runner says judge each file a coverage map covers,
 * asked once per file, so reach, the content keys and the keys of the units
 * a plan considers read one answer.
 *
 * @internal
 */
final readonly class MapJudges
{
    /** @param array<string, Paths|CannotJudge> $judged by each covered file's path, in the map's order */
    private function __construct(private Runner $runner, private CoverageMap $map, private array $judged)
    {
    }

    public static function of(Runner $runner, CoverageMap $map): self
    {
        $judged = [];

        foreach ($map->files() as $file) {
            $judged[$file->value()] = $runner->judges($file, $map);
        }

        return new self($runner, $map, $judged);
    }

    /** The map whose files are judged. */
    public function map(): CoverageMap
    {
        return $this->map;
    }

    /** The test files that judge a file, as the runner says: asked once for a covered file, and now for any other. */
    public function for(Path $file): Paths|CannotJudge
    {
        return array_key_exists($file->value(), $this->judged)
            ? $this->judged[$file->value()]
            : $this->runner->judges($file, $this->map);
    }

    /** Each covered file with the test files that judge it, leaving out a file the runner cannot tell of. */
    public function judges(): Judges
    {
        $judges = Judges::none();

        foreach ($this->map->files() as $file) {
            $tests = $this->judged[$file->value()];
            $judges = $tests instanceof Paths ? $judges->judging($file, $tests) : $judges;
        }

        return $judges;
    }

    /** Every test file that judges a covered file, in the order the map first names it. */
    public function known(): Paths
    {
        $known = [];

        foreach ($this->judged as $tests) {
            foreach ($tests instanceof Paths ? $tests : Paths::none() as $test) {
                $known[] = $test;
            }
        }

        return Paths::of(...$known);
    }
}
