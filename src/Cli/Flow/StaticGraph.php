<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\GraphUse;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Core\Reach\GraphReach;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\ReachGraph;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Test\Role;

/**
 * Outbreak's static graph of the project, as a run asks it (ADR-0132): every
 * PHP file outside the test directories and every support file a source,
 * every file of test cases a test, and every other file of the test
 * directories, such as `tests/Pest.php`, loaded by every test; a file a
 * change deleted or moved away read from its text at the base. It asks only
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

        $question = self::over($suite);
        $about = [];

        foreach ($changes as $changed) {
            foreach ($changed instanceof Changes ? $changed : Changes::none() as $change) {
                foreach ([$change->path(), $change->previousPath()] as $path) {
                    $about += $path->isPhp() ? [$path->value() => $path] : [];
                }
            }
        }

        foreach ($about as $path) {
            $gone = self::gone($question, $path, $sources);
            $question = $gone instanceof Contents
                ? $question->given($path, $gone, self::isTest($layout, $packages, $path))
                : $question;
        }

        $question = $question->about(Paths::of(...$about));
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
        $question = self::over($suite)->about($files);

        if (! $this->settings->shards()->outbreak()->graph()->addsToQuickRounds()
            || ! $graph instanceof ReachGraph
            || ! $question->asks()) {
            return NotGiven::value();
        }

        $answer = $graph->answered($question);

        return $answer instanceof GraphAnswer ? $answer : NotGiven::value();
    }

    /** The question of the graph of a suite's files as they are on disk, asking nothing yet. */
    public static function over(Suite $suite): GraphQuestion
    {
        $files = ['sources' => [], 'tests' => [], 'always' => []];

        foreach ($suite->outside() as $file) {
            if ($file->path()->isPhp()) {
                $files['sources'][] = $file->path();
            }
        }

        foreach ($suite->files() as $file) {
            $path = $file->fingerprint()->path();
            $role = $file->role();

            if ($path->isPhp()) {
                $files[$role === Role::TestCase ? 'tests' : 'sources'][] = $path;
            }

            if ($path->isPhp() && $role === Role::Loaded) {
                $files['always'][] = $path;
            }
        }

        return GraphQuestion::over(
            Paths::of(...$files['sources']),
            Paths::of(...$files['tests']),
            Paths::of(...$files['always']),
        );
    }

    /** The text at the base of a file the disk no longer holds; none where the disk holds it or no text was read. */
    private static function gone(GraphQuestion $question, Path $path, Sources $sources): Contents|NotGiven
    {
        $before = $question->sources()->has($path) || $question->tests()->has($path)
            ? NotGiven::value()
            : $sources->before($path);

        return $before instanceof Contents ? $before : NotGiven::value();
    }

    private static function isTest(Layout $layout, Packages $packages, Path $path): bool
    {
        return $layout->isTest($path->relativeTo($packages->holding($path)->path()));
    }
}
