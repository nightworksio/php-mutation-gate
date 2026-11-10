<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * How the suite ran in the streams of a run planned without a fresh map
 * (ADR-0135): the verdict merges what every shard left of it, names the
 * judging suites the streams ran, and leaves it, with the map the streams
 * recorded, where a job that runs the suite takes the streamed suites from.
 * Where any shard left none, or one that cannot be read, it leaves nothing,
 * and that job runs the whole suite.
 *
 * @internal
 */
final readonly class StreamedSuites
{
    /** The most bytes one shard's record of its streamed tests may hold. */
    private const int MOST_BYTES = 64 * 1024 * 1024;

    private const string SUITE = '%s/suite.json';

    /** The suite left beside the map the streams recorded; nothing where any shard left none that can be read. */
    public static function left(Composed $composed, Plan $plan, Path $results, CoverageMap $map): Written|CannotJudge|NotGiven
    {
        $suite = StreamedSuite::none();

        foreach ($plan as $shard) {
            $contents = $composed->adapters->project->readAtMost(
                Workspace::streamedSuite($results, $shard->id()),
                self::MOST_BYTES,
            );
            $read = $contents instanceof Contents ? StreamedSuite::decoded($contents->text()) : NotGiven::value();

            if (! $read instanceof StreamedSuite) {
                return NotGiven::value();
            }

            $suite = $suite->and($read);
        }

        $names = [];

        foreach ($composed->settings->triage()->suites()->everyUnit() as $name) {
            $names[] = $name->value();
        }

        $written = $composed->adapters->project->write(
            CoverageMapFile::in(Workspace::suite()),
            Contents::of(CoverageMapFile::encode($map, Measuring::now($composed->adapters))),
        );

        return $written instanceof CannotJudge ? $written : $composed->adapters->project->write(
            Path::of(sprintf(self::SUITE, Workspace::suite()->value())),
            Contents::of($suite->ofSuites($names)->encoded()),
        );
    }
}
