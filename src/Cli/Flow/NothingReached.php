<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\ReachingNothing;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * The verdict that stands for a plan that runs no unit, where the commit its
 * change is read since passed on the default branch ({@see ReachingNothing}):
 * only for a standard run that reads its change since a ref, takes no result
 * of its own scope, proves no unit it reaches by an earlier result (ADR-0108),
 * has a config that reads no file it cannot name, and, in a
 * pull request or merge group, is not held to commit each raise with it.
 *
 * @internal
 */
final readonly class NothingReached
{
    private const string NOT_STANDARD = 'A run narrowed or recording more than first killers judges what it carries.';

    private const string OWN_SCOPE = 'The plan takes %d results of the run\'s own scope.';

    private const string PROVED = 'The change reaches %d units earlier results prove, which this verdict judges.';

    private const string RAISES
        = 'A proposed change must commit each raise with it, so its verdict judges what it carries.';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /**
     * What the plan of these shards tells the verdict: the commit whose
     * verdict stands, where it cuts none ({@see of()}); nothing besides
     * elsewhere.
     */
    public function briefing(
        Shards|CannotJudge $shards,
        Inventory $inventory,
        Ledgers $ledgers,
        ChangeBase|CannotTell $base,
        RunProfile $kind,
        int $ownScopeProofs,
        int $proved,
    ): Briefing {
        $stands = $shards instanceof Shards && count($shards) === 0
            ? $this->of($inventory, $ledgers, $base, $kind, $ownScopeProofs, $proved)
            : NotGiven::value();

        return $stands instanceof Unchanged ? Briefing::standard()->unchangedSince($stands) : Briefing::standard();
    }

    /**
     * The verdict that stands for a plan that runs no unit, of a run of this
     * kind, reading its change since this base, that takes this many results
     * of its own scope; or why its verdict judges what it carries.
     */
    private function of(
        Inventory $inventory,
        Ledgers $ledgers,
        ChangeBase|CannotTell $base,
        RunProfile $kind,
        int $ownScopeProofs,
        int $proved,
    ): Unchanged|Reason {
        $proposed = $inventory->standing->runOn()->isProposed();
        $unnamed = $this->adapters->configReads->unnamedBecause();

        return match (true) {
            ! $base instanceof ChangeBase => Reason::that($base->why()),
            ! $kind->equals(RunProfile::standard()) => Reason::that(self::NOT_STANDARD),
            $ownScopeProofs > 0 => Reason::that(sprintf(self::OWN_SCOPE, $ownScopeProofs)),
            $proved !== 0 => Reason::that(sprintf(self::PROVED, $proved)),
            is_string($unnamed) => Reason::that($unnamed),
            $proposed && $this->settings->floors()->improvement() === Improvement::Require
                => Reason::that(self::RAISES),
            default => $this->standing($inventory->trees, $ledgers, $base),
        };
    }

    private function standing(Trees $trees, Ledgers $ledgers, ChangeBase $base): Unchanged|Reason
    {
        $default = $ledgers->defaultBranch();
        $passed = $default->runs()->passed();

        return ReachingNothing::within(
            $trees,
            $this->settings->floors()->baseline(),
            $this->configFiles(),
            $this->settings->ci()->check(),
        )
            ->stands(
                $passed,
                $default->proofs(),
                $base->ref(),
                $passed instanceof Passed ? $this->adapters->changes->changesFrom($passed->commit()) : $passed,
                $this->adapters->changes->changesSince($base->ref()),
                $this->settings->ignores()->entries(),
                $this->setup->clock->now(),
            );
    }

    /** The files the config is in: each name the gate finds one by, the file the run names, and each it reads. */
    private function configFiles(): Globs
    {
        $files = Globs::of(...array_map(Glob::of(...), Format::fileNames()));

        foreach ($this->adapters->configReads->files() as $read) {
            $files = $files->with(Glob::of($read->value()));
        }

        $named = $this->setup->configFile;

        return $named instanceof Path ? $files->with(Glob::of($named->value())) : $files;
    }
}
