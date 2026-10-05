<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * Which test files' entries a kept map holds moved: each whose key differs
 * from the one kept, or that has none, is measured again; each the kept map
 * keys that is gone is dropped; and whether every test the kept map holds is
 * still in a test file.
 */
final readonly class Moving
{
    private function __construct(
        private Paths $moved,
        private TestIds $held,
        private bool $placesEvery,
        private int $total,
    ) {
    }

    /** What moved of a kept map, as the project's test files stand; or why their tests are unknown. */
    public static function of(CoverageEntries $entries, KeptMap $kept): self|CannotJudge
    {
        $map = $kept->map();
        $executed = ExecutedFiles::in($map);
        $files = $entries->testFiles();
        $moved = [];

        foreach ($files as $file) {
            $key = $entries->keyOf($file, $map, $executed);

            if ($key instanceof CannotJudge) {
                return $key;
            }

            $was = $kept->keys()->keyOf($file);
            $moved = $was instanceof Digest && $was->value() === $key->value() ? $moved : [...$moved, $file];
        }

        $gone = [];

        foreach ($kept->keys()->files() as $file) {
            $gone = $files->has($file) ? $gone : [...$gone, $file];
        }

        $held = $entries->testsIn(Paths::of(...$moved, ...$gone), $map);
        $placed = $entries->testsIn(Paths::of(...$files, ...$gone), $map);

        return match (true) {
            $held instanceof CannotJudge => $held,
            $placed instanceof CannotJudge => $placed,
            default => new self(
                Paths::of(...$moved),
                $held,
                count($map->tests()->without($placed)) === 0,
                count($files),
            ),
        };
    }

    /** The test files measured again: each whose key moved, and each new one. */
    public function moved(): Paths
    {
        return $this->moved;
    }

    /** The tests whose kept entries are replaced or dropped: those of the moved test files and the gone ones. */
    public function held(): TestIds
    {
        return $this->held;
    }

    /** Whether every test the kept map holds is in a test file, as the runner names tests after their files. */
    public function placesEvery(): bool
    {
        return $this->placesEvery;
    }

    /** Whether every test file moved, where there is any. */
    public function movesEvery(): bool
    {
        return $this->total > 0 && count($this->moved) === $this->total;
    }

    /** How many test files the project has. */
    public function total(): int
    {
        return $this->total;
    }
}
