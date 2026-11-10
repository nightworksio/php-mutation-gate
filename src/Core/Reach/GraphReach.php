<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Config\GraphUse;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * What Outbreak's static graph adds to the reach rules in a run, as
 * `outbreak.graph` says (ADR-0132). Under `narrow`, a change the coverage map
 * cannot place reaches every file the tests that reach it reach, in place of
 * every unit of its package, where the graph answers. Under `widen`, a
 * changed source file also reaches the files the map shows the test files
 * that reach it running, or, with no map, the files they reach. The map stays
 * the check: nothing the map reaches is left out, and a test file the map
 * shows the graph wrong of gives no answer.
 *
 * @internal
 */
final readonly class GraphReach
{
    private const string NARROWED
        = '`%s` reaches the %d files the tests that reach it reach, by the static graph (`outbreak.graph`: narrow).';

    private const string WIDENED = '`%s` changed, and the static graph joins %d test files to it, so the %d files'
        . ' they run are reached too (`outbreak.graph`: widen).';

    private const string WRONG = 'The coverage map shows %d test files running files the static graph does not join'
        . ' to them, so the graph answers nothing from them: %s.';

    public function __construct(
        private GraphUse $use = GraphUse::Off,
        private GraphAnswer|NotGiven $answer = new NotGiven(),
    ) {
    }

    /**
     * This reach, with the files the graph joins to a change the coverage map
     * cannot place and the files the map gives it; none where the graph does
     * not narrow, or gives no answer, and the change reaches as the rules say.
     */
    public function narrowed(Reach $reach, Path $path, Paths $mapped): Reach|NotGiven
    {
        $answer = $this->use === GraphUse::Narrow ? $this->answer : NotGiven::value();
        $joined = $answer instanceof GraphAnswer ? $answer->joined($path) : $answer;

        if (! $joined instanceof Paths) {
            return $joined;
        }

        $files = $joined->and($mapped);

        return $reach->files($files, Reason::that(sprintf(self::NARROWED, $path->value(), count($files))));
    }

    /**
     * This reach, with the files the test files the graph joins to a changed
     * source file run, by the coverage map, or reach, with none; as it is
     * where the graph does not widen or gives no answer.
     */
    public function widened(Reach $reach, Path $source, Judges|NoMap $coverage): Reach
    {
        $answer = $this->use === GraphUse::Widen ? $this->answer : NotGiven::value();
        $tests = $answer instanceof GraphAnswer ? $answer->reaching($source) : $answer;

        if (! $answer instanceof GraphAnswer || ! $tests instanceof Paths) {
            return $reach;
        }

        $files = $coverage instanceof Judges ? self::runBy($tests, $coverage) : $answer->joined($source);

        return $files instanceof Paths && count($files) > 0
            ? $reach->files(
                $files,
                Reason::that(sprintf(self::WIDENED, $source->value(), count($tests), count($files))),
            )
            : $reach;
    }

    /** This reach, saying which test files the coverage map shows the graph wrong of, where it acted and is. */
    public function noted(Reach $reach): Reach
    {
        $wrong = $this->answer instanceof GraphAnswer ? $this->answer->wrong() : Paths::none();
        $named = [];

        foreach ($wrong as $test) {
            $named[] = sprintf('`%s`', $test->value());
        }

        return count($wrong) > 0
            ? $reach->because(Reason::that(sprintf(self::WRONG, count($wrong), implode(', ', $named))))
            : $reach;
    }

    private static function runBy(Paths $tests, Judges $coverage): Paths
    {
        $files = Paths::none();

        foreach ($tests as $test) {
            $files = $files->and($coverage->filesRunBy($test));
        }

        return $files;
    }
}
