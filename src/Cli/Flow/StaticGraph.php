<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\GraphUse;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphReach;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\ReachGraph;
use NightWorksIO\MutationGate\Core\Reach\Sources;

/**
 * Outbreak's static graph of the project, as a run asks it (ADR-0132), over
 * the question SuiteQuestion puts of the suite. It asks only
 * where `outbreak.graph` narrows or widens, a binary is in use and the
 * change touched a PHP file, about each such file, checked by the coverage
 * map where there is one; and, for a quick round of `watch`, which test
 * files reach the files a save changed.
 *
 * @internal
 */
final readonly class StaticGraph
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** What the graph adds to a run's reach of these changes; nothing where it acts in no run or answers nothing. */
    public function forReach(
        Suite $suite,
        Layout $layout,
        Packages $packages,
        Sources $sources,
        Judges|NoMap $map,
        Changes|CannotTell ...$changes,
    ): GraphReach {
        $use = $this->settings->shards()->outbreak()->graph();
        $graph = $this->adapters->maps;

        if ($use !== GraphUse::Narrow && $use !== GraphUse::Widen || ! $graph instanceof ReachGraph) {
            return new GraphReach();
        }

        $question = SuiteQuestion::aboutChanges($suite, $layout, $packages, $sources, ...$changes);
        $question = $map instanceof Judges ? $question->checkedBy($map) : $question;
        $answer = $question->asks() ? $graph->answered($question) : NotGiven::value();

        return new GraphReach($use, $answer instanceof GraphAnswer ? $answer : NotGiven::value());
    }

    /**
     * Which test files reach each of these files, for a quick round of
     * `watch` (ADR-0146); nothing where `outbreak.graph` is off, no binary is
     * in use, or the graph answers nothing.
     */
    public function forQuickRound(Suite $suite, Paths $files): GraphAnswer|NotGiven
    {
        $graph = $this->adapters->maps;
        $question = SuiteQuestion::over($suite)->about($files);

        if (! $this->settings->shards()->outbreak()->graph()->addsToQuickRounds()
            || ! $graph instanceof ReachGraph
            || ! $question->asks()) {
            return NotGiven::value();
        }

        $answer = $graph->answered($question);

        return $answer instanceof GraphAnswer ? $answer : NotGiven::value();
    }
}
