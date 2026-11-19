<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_values;
use function count;
use function file_get_contents;
use function is_file;
use function ksort;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\EndedProcesses;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitTestList;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * Some suites' tests and the groups each is in, as Pest lists them into a file of the adapter's own, running none.
 *
 * @internal
 */
final readonly class Listed
{
    /** The file a listing side by side with the first lists into, by its place from one. */
    private const string BESIDE = 'tests-%d.xml';

    /** What Pest lists for these suites, never seeing the variables withheld; or why it lists nothing. */
    public static function of(
        Project $project,
        Shell $shell,
        Withheld $withheld,
        Suites $suites,
    ): TestListing|CannotJudge {
        $file = $project->fresh(PhpUnitTestList::FILE);

        if ($file instanceof CannotJudge) {
            return $file;
        }

        $ran = $shell->run(Invocation::of($project)->listing($withheld, $suites, $file));
        $xml = is_file($file) ? file_get_contents($file) : false;

        return PhpUnitTestList::listedIn($ran, $xml, $file, Program::Pest);
    }

    /**
     * What Pest lists for each set of suites, in their order, each listing
     * started side by side with the others into a file of its own, the first
     * into the file {@see of()} lists into, and read once taken; or why it
     * lists nothing for one.
     */
    public static function started(
        Project $project,
        Shell $shell,
        Withheld $withheld,
        Suites ...$suites,
    ): PendingListings {
        $refused = [];
        $files = [];
        $commands = [];

        foreach (array_values($suites) as $place => $among) {
            $file = $project->fresh($place === 0 ? PhpUnitTestList::FILE : sprintf(self::BESIDE, $place + 1));

            if ($file instanceof CannotJudge) {
                $refused[$place] = $file;

                continue;
            }

            $files[$place] = $file;
            $commands[] = Invocation::of($project)->listing($withheld, $among, $file);
        }

        $started = self::start($shell, ...$commands);

        return PendingListings::taking(
            static function () use ($files, $started, $refused): array {
                $listed = self::read($files, [...$started->ends()]) + $refused;
                ksort($listed);

                return array_values($listed);
            },
            static function () use ($started): void {
                $started->stop();
            },
        );
    }

    /** The listings started side by side, where the shell starts them; else run to their ends at once. */
    private static function start(Shell $shell, Command ...$commands): StartedProcesses
    {
        if ($commands === []) {
            return EndedProcesses::of(ProcessEnds::of());
        }

        $slots = WorkerSlots::untold(count($commands));

        return $shell instanceof StartsCommands
            ? $shell->started($slots, Unlimited::time(), ...$commands)
            : EndedProcesses::of($shell->sideBySide($slots, Unlimited::time(), ...$commands));
    }

    /**
     * What each listing listed into its file.
     *
     * @param  array<int, string>                    $files each listing's file, by its place
     * @param  list<Ran>                             $ends  how each listing ended, in the order of the files
     * @return array<int, TestListing|CannotJudge> by place
     */
    private static function read(array $files, array $ends): array
    {
        $listed = [];

        foreach (array_keys($files) as $at => $place) {
            $xml = is_file($files[$place]) ? file_get_contents($files[$place]) : false;
            $listed[$place] = PhpUnitTestList::listedIn($ends[$at], $xml, $files[$place], Program::Pest);
        }

        return $listed;
    }
}
