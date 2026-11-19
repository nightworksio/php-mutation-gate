<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function intdiv;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The runs of a heavy mutant's parts (ADR-0008): each part with the mutant
 * served in its file's place, allowed the standard limit of its own tests
 * and stopped where no test finishes for the silence limit of its slowest;
 * and a part that ran out unmutated split by the test files its tests are
 * in, where it holds more than one: around the file of the test it was
 * stopped in, or in two halves where no test is named.
 *
 * @internal
 */
final readonly class PartControls
{
    private function __construct(
        private Mutant $mutant,
        private Contents $text,
        private CoverageMap $map,
        private LimitBounds $bounds,
        private TestPlaces $places,
    ) {
    }

    /** The runs of a mutant's parts, served this text, timed by this map within these bounds. */
    public static function of(
        Mutant $mutant,
        Contents $text,
        CoverageMap $map,
        LimitBounds $bounds,
        TestPlaces $places,
    ): self {
        return new self($mutant, $text, $map, $bounds, $places);
    }

    /** The run of a part, served the mutant. */
    public function serving(RunTests $part): Control
    {
        $silent = $this->bounds->silenceOf(RunnerMutatorName::of($this->mutant->mutation()->mutator()));

        return Control::running(
            $this->mutant->location()->file(),
            $part,
            MutantLimit::standard()->of(OwnTime::of($this->map, $part->tests()), $this->bounds),
        )->serving($this->text, MutantLimit::standard()->of(OwnTime::slowest($this->map, $part->tests()), $silent))
            ->watched(TestWatch::of($this->bounds->perTest(), OwnTime::each($this->map, $part->tests())));
    }

    /**
     * The run of a part that ran out unmutated, split by its test files,
     * each part served the mutant: around the file of the test it was
     * stopped in, the files before it, that file and the files after it;
     * in two, the first half of its files and the rest, where it names no
     * test of the part. None where its tests are all in one file, or in
     * none.
     *
     * @return list<Control>
     */
    public function split(Control $part, TestId|NotGiven $stoppedIn): array
    {
        $files = $this->filesOf($part->tests());
        $at = $this->holding($files, $stoppedIn);
        $half = intdiv(count($files) + 1, 2);
        $sides = $at instanceof NotGiven
            ? [array_slice($files, 0, $half), array_slice($files, $half)]
            : [array_slice($files, 0, $at), [$files[$at]], array_slice($files, $at + 1)];

        return count($files) < 2 ? [] : array_map(
            fn(array $side): Control => $this->serving(RunTests::of($this->joined($side))),
            array_values(array_filter($sides, static fn(array $side): bool => $side !== [])),
        );
    }

    /** The test file a part's tests are all in; none where they are in more than one, or in none. */
    public function fileOf(Control $part): Path|NotGiven
    {
        $held = $this->places->holding($part->tests());
        $placed = TestIds::none();

        foreach ($held as [, $tests]) {
            $placed = $placed->and($tests);
        }

        return count($held) === 1 && count($part->tests()->without($placed)) === 0 ? $held[0][0] : NotGiven::value();
    }

    /**
     * These tests by the file each is in, in their order, those in no file together last.
     *
     * @return list<TestIds>
     */
    private function filesOf(TestIds $tests): array
    {
        $files = [];
        $placed = TestIds::none();

        foreach ($this->places->holding($tests) as [, $held]) {
            $files[] = $held;
            $placed = $placed->and($held);
        }

        $unplaced = $tests->without($placed);

        return count($unplaced) > 0 ? [...$files, $unplaced] : $files;
    }

    /**
     * Where among these tests by file the file holding this test is; none
     * where none holds it, or no test is named.
     *
     * @param list<TestIds> $files
     */
    private function holding(array $files, TestId|NotGiven $test): int|NotGiven
    {
        foreach ($files as $at => $tests) {
            if ($test instanceof TestId && $tests->has($test)) {
                return $at;
            }
        }

        return NotGiven::value();
    }

    /** @param list<TestIds> $sets */
    private function joined(array $sets): TestIds
    {
        $joined = TestIds::none();

        foreach ($sets as $set) {
            $joined = $joined->and($set);
        }

        return $joined;
    }
}
