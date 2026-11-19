<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * Which test files' entries a kept map holds moved: each whose key differs
 * from the one kept, or that has none, is measured again; each the kept map
 * keys that is gone is dropped; and whether every test the kept map holds is
 * still in a test file.
 *
 * @internal
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

    public static function of(
        CoverageEntries $entries,
        KeptMap $kept,
        QuickCoverage|NotGiven $quick = new NotGiven(),
    ): self|CannotJudge {
        $map = $kept->map();
        $outline = MapOutline::of($map);
        $files = $entries->testFiles();
        $moved = self::movedSince($kept, $entries, $quick, $map, $outline);

        if ($moved instanceof CannotJudge) {
            return $moved;
        }

        $gone = array_filter([...$kept->keys()->files()], static fn(Path $file): bool => ! $files->has($file));

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

    /**
     * What moved of a kept map, as the project's test files stand, of which
     * a quick round measures again only what it says (ADR-0146); or why
     * their tests are unknown.
     */
    /**
     * Each test file whose key moved from the kept map's, where a quick round measures it.
     *
     * @return list<Path>|CannotJudge
     */
    private static function movedSince(
        KeptMap $kept,
        CoverageEntries $entries,
        QuickCoverage|NotGiven $quick,
        CoverageMap $map,
        MapOutline $outline,
    ): array|CannotJudge {
        $moved = [];

        foreach ($entries->testFiles() as $file) {
            $key = $entries->keyOf($file, $map, $outline);

            if ($key instanceof CannotJudge) {
                return $key;
            }

            $was = $kept->keys()->keyOf($file);
            $unchanged = $was instanceof Digest && $was->value() === $key->value();
            $measured = ! $quick instanceof QuickCoverage || $quick->measures($file, $was instanceof Digest);
            $moved = $unchanged || ! $measured ? $moved : [...$moved, $file];
        }

        return $moved;
    }
}
