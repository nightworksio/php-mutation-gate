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
use NightWorksIO\MutationGate\Core\Runner\PhpUnitTestList;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
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
     * run side by side with the others into a file of its own, the first into
     * the file {@see of()} lists into; or why it lists nothing for one.
     *
     * @return list<TestListing|CannotJudge>
     */
    public static function sideBySide(Project $project, Shell $shell, Withheld $withheld, Suites ...$suites): array
    {
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

        $listed = self::read($files, self::ran($shell, ...$commands)) + $refused;
        ksort($listed);

        return array_values($listed);
    }

    /** @return list<Ran> how each listing ended, in the order the commands were given */
    private static function ran(Shell $shell, Command ...$commands): array
    {
        $count = count($commands);

        return $count === 0
            ? []
            : [...$shell->sideBySide(WorkerSlots::untold($count), Unlimited::time(), ...$commands)];
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
