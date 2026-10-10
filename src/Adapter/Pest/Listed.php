<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitTestList;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/** Some suites' tests and the groups each is in, as Pest lists them into a file of the adapter's own, running none. */
final readonly class Listed
{
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

        $ran = $shell->run(Invocation::installedIn($project->vendor())->listing($withheld, $suites, $file));
        $xml = is_file($file) ? file_get_contents($file) : false;

        return PhpUnitTestList::listedIn($ran, is_string($xml) ? $xml : '', $file, Program::Pest);
    }
}
