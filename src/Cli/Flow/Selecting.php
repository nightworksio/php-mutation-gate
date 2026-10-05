<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;

use function sprintf;

/**
 * `affected`: the tests a change can make fail (ADR-0020, decisions 1 to 3),
 * from the gate's own coverage map, in a directory or else the one the
 * default branch's runs keep, and git, running nothing. The change is
 * every change since the commit the map was measured at, and with
 * `--changed-since` every change since that ref besides. A map that is not
 * there, cannot be read, does not say where it was measured or was measured
 * in a dirty tree lists every test, as does a change since its commit git
 * cannot tell.
 */
final readonly class Selecting
{
    private const string NO_MAP
        = 'No coverage map is at %s, and the default branch keeps none, so every test is listed.';

    private const string UNREADABLE = '%s So every test is listed.';

    private const string UNPLACED = 'The coverage map does not say where it was measured, so every test is listed.';

    private const string DIRTY = 'The coverage map was measured in a dirty working tree, so every test is listed.';

    private const string SINCE_MAP
        = 'What changed since %s, where the coverage map was measured, cannot be told. %s So every test is listed.';

    private const string NONE_PASSED = 'No commit of this scope has passed yet, so every test is listed.';

    private const string CANNOT_TELL = 'Git cannot tell what changed since %s. %s Run the whole suite.';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /**
     * The tests the change since a ref, or since the map's commit where the
     * ref is empty, can make fail, by the map in a directory; or why git
     * cannot say what changed.
     */
    public function selected(string $since, Path $coverage): Selected|CannotJudge
    {
        $inventory = Inventory::of($this->adapters, $this->settings);

        if ($inventory instanceof CannotJudge) {
            return $inventory;
        }

        $base = $this->baseOf($since, $inventory);
        $asked = $base instanceof Revision ? $this->adapters->changes->changesSince($base) : Changes::none();

        if ($asked instanceof CannotTell) {
            return CannotJudge::because(sprintf(self::CANNOT_TELL, $since, $asked->why()));
        }

        $read = $this->mapIn($coverage, $inventory);
        $map = $read instanceof Read ? $read->map : CoverageMap::empty();
        $places = TestsOfTheSuite::placed($this->adapters, $inventory->suite, $map);
        $measured = $read instanceof Read ? $read->at : NotGiven::value();

        return match (true) {
            $base instanceof Reason => Selected::of(AffectedTests::every($places, $base), NotGiven::value(), $measured),
            ! $read instanceof Read => Selected::of(AffectedTests::every($places, $read), $base, $measured),
            default => Selected::of($this->since($read, $asked, $base, $inventory, $places), $base, $measured),
        };
    }

    /** The ref the change is read since: none where none is given, the newest passed commit for `last-passed`. */
    private function baseOf(string $since, Inventory $inventory): Revision|NotGiven|Reason
    {
        if ($since === '') {
            return NotGiven::value();
        }

        $ledgers = Ledgers::read($this->adapters->proofs, $inventory->standing, Writing::Never);
        $base = Mode::since($since)->base($ledgers);

        return $base instanceof Revision ? $base : Reason::that(self::NONE_PASSED);
    }

    /**
     * The map in a directory, or, where none is there, the one the default
     * branch's runs keep beside its ledger, with where it was measured; or why
     * every test is listed.
     */
    private function mapIn(Path $directory, Inventory $inventory): Read|Reason
    {
        $file = CoverageMapFile::in($directory);
        $contents = $this->adapters->project->read($file);
        $map = $contents instanceof Contents ? CoverageMapFile::decode($contents->text()) : $contents;

        return match (true) {
            $map instanceof CoverageMap => Read::of($map, MeasuredAt::recordedIn($contents->text())),
            $map instanceof CannotJudge => Reason::that(sprintf(self::UNREADABLE, $map->why())),
            default => $this->keptMap($inventory, $file),
        };
    }

    /** The map the default branch's runs keep beside its ledger; or why every test is listed. */
    private function keptMap(Inventory $inventory, Path $file): Read|Reason
    {
        $standing = $inventory->standing;
        $access = Access::of($standing->runOn()->scope(), $standing->defaultBranch(), Writing::Never);
        $kept = KeptCoverage::fromStore($this->adapters->proofs, $access);

        return match (true) {
            $kept instanceof KeptMap => Read::of($kept->map(), $kept->measuredAt()),
            $kept instanceof CannotJudge => Reason::that(sprintf(self::UNREADABLE, $kept->why())),
            default => Reason::that(sprintf(self::NO_MAP, $file->value())),
        };
    }

    private function since(
        Read $read,
        Changes $asked,
        Revision|NotGiven $base,
        Inventory $inventory,
        TestPlaces $places,
    ): AffectedTests {
        $at = $read->at;

        if (! $at instanceof MeasuredAt || $at->isDirty()) {
            $why = $at instanceof MeasuredAt ? self::DIRTY : self::UNPLACED;

            return AffectedTests::every($places, Reason::that($why));
        }

        $sinceMap = $this->adapters->changes->changesFrom($at->commit());

        return $sinceMap instanceof CannotTell
            ? AffectedTests::every(
                $places,
                Reason::that(sprintf(self::SINCE_MAP, $at->commit()->name(), $sinceMap->why())),
            )
            : new AffectedRules($this->adapters, $this->settings, $inventory, $read->map)
                ->of(AskedChanges::of($sinceMap, $at->commit(), $asked, $base), $places);
    }
}
