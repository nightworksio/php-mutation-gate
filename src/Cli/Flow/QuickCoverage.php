<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\QuickCovered;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Local\LocalLoop;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function substr_count;

/**
 * How a quick round of `watch` brings the last round's map up to date
 * (ADR-0146): it measures again only the test files that changed themselves
 * or that map holds nothing of, and covers each source file the save changed
 * by the tests the map shows running it and those the static graph says
 * reach it (see QuickCovered). Where the round places test files
 * (ADR-0147), a changed test file the map holds and the graph answers for
 * is not measured: its tests keep their entries and cover every line of
 * each file the graph says the test file reaches, until a stream records
 * them.
 *
 * @internal
 */
final readonly class QuickCoverage
{
    private const string LINE = "\n";

    private function __construct(
        private Paths $changed,
        private Paths $sources,
        private GraphAnswer|NotGiven $graph,
        private Paths $placed,
    ) {
    }

    /** A quick round's, over a suite whose test files these entries name, asking the static graph about its sources. */
    public static function of(
        Adapters $adapters,
        Settings $settings,
        Suite $suite,
        CoverageEntries $entries,
        QuickRound $round,
    ): self {
        $outside = [];

        foreach ($suite->outside() as $file) {
            $outside[] = $file->path();
        }

        $outside = Paths::of(...$outside);
        $places = $adapters->loop instanceof LocalLoop && $adapters->loop->placesTestFiles();
        $sources = [];
        $tests = [];

        foreach ($round->changed() as $path) {
            $test = $entries->testFiles()->has($path);
            $sources = $path->isPhp() && $outside->has($path) && ! $test ? [...$sources, $path] : $sources;
            $tests = $places && $path->isPhp() && $test ? [...$tests, $path] : $tests;
        }

        $sources = Paths::of(...$sources);
        $asked = $sources->and(Paths::of(...$tests));
        $graph = count($asked) === 0
            ? NotGiven::value()
            : new StaticGraph($adapters, $settings)->forQuickRound($suite, $asked);

        return new self($round->changed(), $sources, $graph, self::placedBy($graph, ...$tests));
    }

    /**
     * Whether a test file whose entries moved is measured again: it changed
     * and the round does not place it, or the kept map keyed none of it.
     */
    public function measures(Path $test, bool $kept): bool
    {
        return ! $kept || ($this->changed->has($test) && ! $this->placed->has($test));
    }

    /** How many source files the save changed, which the round covers without measuring them. */
    public function sources(): int
    {
        return count($this->sources);
    }

    /**
     * The map with each changed source file still on disk covered on every
     * line by its tests in it, and each file a placed test file reaches by
     * that file's tests; or why the tests of a test file cannot be told.
     */
    public function over(CoverageMap $map, CoverageEntries $entries, Adapters $adapters): CoverageMap|CannotJudge
    {
        $covered = [];

        foreach ($this->sources as $source) {
            $text = $adapters->project->read($source);
            $reaching = $this->graph instanceof GraphAnswer ? $this->graph->reaching($source) : NotGiven::value();
            $reached = $reaching instanceof Paths ? $entries->testsIn($reaching, $map) : TestIds::none();

            if ($reached instanceof CannotJudge) {
                return $reached;
            }

            $covered = $text instanceof Contents
                ? [
                    ...$covered,
                    QuickCovered::of(
                        $source,
                        substr_count($text->text(), self::LINE) + 1,
                        $map->testsCoveringFile($source)->and($reached),
                    ),
                ]
                : $covered;
        }

        $placed = $this->placedOver($map, $entries, $adapters);

        return $placed instanceof CannotJudge ? $placed : QuickCovered::over($map, ...$covered, ...$placed);
    }

    /**
     * Each file a placed test file reaches, on disk, covered on every line
     * by that test file's tests; or why the tests of a test file cannot be
     * told.
     *
     * @return list<QuickCovered>|CannotJudge
     */
    private function placedOver(CoverageMap $map, CoverageEntries $entries, Adapters $adapters): array|CannotJudge
    {
        $covered = [];

        foreach ($this->placed as $test) {
            $joined = $this->graph instanceof GraphAnswer ? $this->graph->joined($test) : NotGiven::value();
            $tests = $entries->testsIn(Paths::of($test), $map);

            if ($tests instanceof CannotJudge) {
                return $tests;
            }

            foreach ($joined instanceof Paths ? $joined : Paths::none() as $file) {
                $text = $adapters->project->read($file);
                $covered = $text instanceof Contents
                    ? [...$covered, QuickCovered::of($file, substr_count($text->text(), self::LINE) + 1, $tests)]
                    : $covered;
            }
        }

        return $covered;
    }

    /** The test files among these the graph says which files they reach. */
    private static function placedBy(GraphAnswer|NotGiven $graph, Path ...$tests): Paths
    {
        $placed = [];

        foreach ($tests as $test) {
            $placed = $graph instanceof GraphAnswer && $graph->joined($test) instanceof Paths
                ? [...$placed, $test]
                : $placed;
        }

        return Paths::of(...$placed);
    }
}
