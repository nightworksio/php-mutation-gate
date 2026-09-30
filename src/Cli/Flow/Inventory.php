<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\PestHolds;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\TreeUnits;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * What a run finds before it runs anything: where it stands, the trees,
 * every file, the test suite, and every unit of the trees, each held path
 * one unit judged by the tests that hold it. Under Pest, whose plugin turns
 * each `#[Holds]` into a group, the groups Pest lists are the holdings, once
 * every path the tokens find is among them (ADR-0005, decision 9).
 */
final readonly class Inventory
{
    private const string NO_FILES = 'The files of the repository cannot be listed, so no unit can be found. %s';

    private function __construct(
        public Standing $standing,
        public Trees $trees,
        public Fingerprints $files,
        public Suite $suite,
        public Units $units,
    ) {
    }

    public static function of(Adapters $adapters, Settings $settings): self|CannotJudge
    {
        $standing = Standing::of($adapters->ci, $adapters->repository, $settings->ci()->defaultBranch());
        $trees = $adapters->trees->trees();
        $files = $adapters->changes->fingerprints();

        return match (true) {
            $standing instanceof CannotJudge => $standing,
            $trees instanceof CannotJudge => $trees,
            $files instanceof CannotTell => CannotJudge::because(sprintf(self::NO_FILES, $files->why())),
            default => self::withSuite($adapters, $standing, $trees, $files),
        };
    }

    private static function withSuite(
        Adapters $adapters,
        Standing $standing,
        Trees $trees,
        Fingerprints $files,
    ): self|CannotJudge {
        $suite = Suite::read($trees, $files, $adapters->project);
        $groups = $adapters->runner->groups($adapters->withheld);
        $holdings = $suite instanceof Suite && $groups instanceof Groups
            ? self::holdingsOf($adapters, $suite, $groups)
            : Holdings::none();
        $held = $holdings instanceof Holdings ? $holdings->units($trees, $files) : $holdings;

        return match (true) {
            $suite instanceof CannotJudge => $suite,
            $groups instanceof CannotJudge => $groups,
            $held instanceof CannotJudge => $held,
            default => new self($standing, $trees, $files, $suite, TreeUnits::of($trees, $files, $held)),
        };
    }

    /** What the groups the runner lists and the `#[Holds]` in the suite declare. */
    private static function holdingsOf(Adapters $adapters, Suite $suite, Groups $groups): Holdings|CannotJudge
    {
        $identity = $adapters->runner->identity();
        $holds = $identity instanceof Identity && $identity->runner() === Pest::RUNNER
            ? $suite->pestHolds($adapters->runner->definitions())
            : Holdings::inGroups($groups)->merge($suite->holdings());

        return $holds instanceof PestHolds ? $holds->listedIn($groups) : $holds;
    }
}
