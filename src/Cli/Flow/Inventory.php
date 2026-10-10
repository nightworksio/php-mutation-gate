<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\Hold\Additions;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\PestHolds;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\TreeUnits;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * What a run finds before it runs anything: where it stands, the trees,
 * every file, the test suite, every unit of the trees, each path held from
 * the suites that judge every unit one unit judged by the tests that hold it,
 * and the holds written only in the holding suites, which add judges to what
 * they hold. Under Pest, whose plugin turns each `#[Holds]` into a group, the
 * groups Pest lists are the holdings, once every path the tokens find is
 * among them (ADR-0005, decision 9).
 */
final readonly class Inventory
{
    private const string NO_FILES = 'The files of the repository cannot be listed, so no unit can be found. %s';

    /** Why a runner that takes no coverage map from the gate cannot judge a hold from the holding suites. */
    private const string UNMAPPED = <<<'SAID'
        %1$s holds %2$s only from the holding suites, which adds their tests to the judges of its lines
        through the coverage map the gate hands the runner. This runner opens each shard on a coverage
        run of its own, as Pest does without pest.patch, so it cannot. Turn on pest.patch, or hold the
        path from a suite tests.suites lists.
        SAID;

    private function __construct(
        public Standing $standing,
        public Trees $trees,
        public Fingerprints $files,
        public Suite $suite,
        public Units $units,
        public Additions $additions,
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
        $listings = $suite instanceof Suite ? Listings::of($adapters) : $suite;

        if (! $listings instanceof Listings) {
            return $listings;
        }

        $holdings = self::holdingsOf($adapters, $suite, $listings->groups());
        $additions = $holdings instanceof Holdings
            ? $holdings->additions($listings->judging, $listings->holding)
            : Additions::none();
        $held = $holdings instanceof Holdings ? $holdings->units($trees, $files, $additions) : $holdings;

        if ($held instanceof CannotJudge) {
            return $held;
        }

        $unmapped = self::unmapped($adapters, $additions);

        return $unmapped instanceof CannotJudge ? $unmapped : new self(
            $standing,
            $trees,
            $files,
            $suite,
            TreeUnits::of($trees, $files, $held->except($held->within($additions->paths()))),
            $additions,
        );
    }

    /** What the groups the runner lists and the `#[Holds]` in the suite declare. */
    private static function holdingsOf(Adapters $adapters, Suite $suite, Groups $groups): Holdings|CannotJudge
    {
        $among = $adapters->narrowing->mappedSuitesFor(WholeSuite::tests());
        $holds = $adapters->runner->behaviour()->holdsAsLoaded()
            ? $suite->pestHolds($adapters->runner->definitions(), $among)
            : Holdings::inGroups($groups)->merge($suite->holdings($among));

        return $holds instanceof PestHolds ? $holds->listedIn($groups) : $holds;
    }

    /** Why a runner that opens each shard on its own coverage run cannot judge these holds; none where it can. */
    private static function unmapped(Adapters $adapters, Additions $additions): CannotJudge|NotGiven
    {
        if (! $adapters->runner->behaviour()->opensEachShard()) {
            return NotGiven::value();
        }

        foreach ($additions as $addition) {
            return CannotJudge::because(sprintf(self::UNMAPPED, $addition->written(), $addition->path()->value()));
        }

        return NotGiven::value();
    }
}
