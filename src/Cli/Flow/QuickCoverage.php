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
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function substr_count;

/**
 * How a quick round of `watch` brings the last round's map up to date
 * (ADR-0146): it measures again only the test files that changed themselves
 * or that map holds nothing of, and covers each source file the save changed
 * by the tests the map shows running it and those the static graph says
 * reach it (see QuickCovered).
 *
 * @internal
 */
final readonly class QuickCoverage
{
    private const string LINE = "\n";

    private function __construct(private Paths $changed, private Paths $sources, private GraphAnswer|NotGiven $graph)
    {
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
        $sources = [];

        foreach ($round->changed() as $path) {
            $sources = $path->isPhp() && $outside->has($path) && ! $entries->testFiles()->has($path)
                ? [...$sources, $path]
                : $sources;
        }

        $sources = Paths::of(...$sources);

        return new self(
            $round->changed(),
            $sources,
            count($sources) === 0
                ? NotGiven::value()
                : new StaticGraph($adapters, $settings)->forQuickRound($suite, $sources),
        );
    }

    /** Whether a test file whose entries moved is measured again: it changed, or the kept map keyed none of it. */
    public function measures(Path $test, bool $kept): bool
    {
        return ! $kept || $this->changed->has($test);
    }

    /** How many source files the save changed, which the round covers without measuring them. */
    public function sources(): int
    {
        return count($this->sources);
    }

    /**
     * The map with each changed source file still on disk covered on every
     * line by its tests in it; or why the tests of a test file cannot be told.
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

        return QuickCovered::over($map, ...$covered);
    }
}
