<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use Traversable;

/**
 * Every loose value the settings hold in force: a value a setting offers as an opt-in that counts what its default
 * doubts, or carries what its default runs again (ADR-0142). The reference sets each in bold.
 *
 * @implements IteratorAggregate<int, LooseValue>
 *
 * @internal
 */
final readonly class LooseValues implements Countable, IteratorAggregate
{
    private const string KILLS = 'a kill counts without its control on the unmutated code';

    private const string NARROWED = 'a kill in a narrowed run counts without its replay, alone run or run again';

    private const string HELD = <<<'SAID'
        a held unit's lines are read from the map,
        and a line its holding tests reach only after another test's state leaves its mutants out of the score
        SAID;

    private const string TRIALS = 'a mutant left uncovered on a line that is not executable is left out of the score';

    private const string DELTA
        = 'a merged pull request whose landed tree differs carries what the change from its judged head does not reach';

    private const string OWN_SCOPE
        = 'a merged pull request\'s results that an earlier commit of it wrote carry onto the default branch';

    private const string FORKS = 'a verdict or results a fork\'s run recorded are reused';

    private const string LOCK = 'a moved package reaches less than every unit';

    private const string ALONE = <<<'SAID'
        a kill's control runs its killers alone, so a test that fails only after the tests before it counts as a kill
        SAID;

    private const string CACHE
        = 'the default branch takes a proof another branch of the repository computed, from the shared cache';

    private const string TOUCHED = <<<'SAID'
        a split stream's kill by a test that touched the clock,
        randomness or I/O after its fork counts without its own run
        SAID;

    private const string BOOT_ONCE = <<<'SAID'
        a split stream runs each test in a process forked from an application booted once,
        where it no longer sees the state the tests before it left
        SAID;

    private const string GRAPH
        = 'a change the coverage map cannot place reaches what the static graph joins to it, not its whole package';

    private const string OFF = 'false';

    /** @param list<LooseValue> $values */
    private function __construct(private array $values)
    {
    }

    /** The loose values these settings hold. */
    public static function in(Settings $settings): self
    {
        $excluded = $settings->floors()->uncovered() === Uncovered::Exclude;
        $reuse = $settings->ci()->reuse();
        $reuses = $reuse->identical() !== IdenticalReuse::Off;
        $merges = $reuse->identical()->reusesOn(defaultBranch: true);
        $reach = $settings->reach();
        $outbreak = $settings->shards()->outbreak();
        $pest = $settings->effective()->running()->pest();
        $split = $pest->splitStream();

        return new self([
            ...self::toggled($settings->toggles(), $excluded),
            ...self::when(
                $pest->narrowedKills() === NarrowedKillTrust::Trust,
                LooseValue::of('pest.narrowedKills', NarrowedKillTrust::Trust->value, self::NARROWED),
            ),
            ...self::when(
                $excluded && $reach->held()->coverage() === HeldCoverageMode::Map,
                LooseValue::of('holds.coverage', HeldCoverageMode::Map->value, self::HELD),
            ),
            ...self::when($merges && $reuse->delta(), LooseValue::of('reuse.delta', 'true', self::DELTA)),
            ...self::when($reuses && $reuse->forks(), LooseValue::of('reuse.forks', 'true', self::FORKS)),
            ...self::when(
                $merges && $reuse->ownScopeProofs() === OwnScopeTrust::Trust,
                LooseValue::of('reuse.ownScopeProofs', OwnScopeTrust::Trust->value, self::OWN_SCOPE),
            ),
            ...self::when(
                $settings->triage()->flaky()->killControls() === KillControl::Alone,
                LooseValue::of('flaky.killControls', KillControl::Alone->value, self::ALONE),
            ),
            ...self::when(
                ! $outbreak->recheckTouchedKills(),
                LooseValue::of('outbreak.recheckTouchedKills', self::OFF, self::TOUCHED),
            ),
            ...self::when(
                $outbreak->graph()->isLoose(),
                LooseValue::of('outbreak.graph', GraphUse::Narrow->value, self::GRAPH),
            ),
            ...self::when(
                $outbreak->cache()->trust()->isLoose(),
                LooseValue::of('outbreak.cache.trust', CacheTrust::Repository->value, self::CACHE),
            ),
            ...self::when(
                $split->enabled() && $split->bootOnce(),
                LooseValue::of('splitStream.bootOnce', 'true', self::BOOT_ONCE),
            ),
            ...self::when(
                $reach->lockMoves() !== LockMoveReach::Everything,
                LooseValue::of('reach.lockMoves', $reach->lockMoves()->value, self::LOCK),
            ),
        ]);
    }

    public function count(): int
    {
        return count($this->values);
    }

    /** @return Traversable<int, LooseValue> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }

    /**
     * The toggles these settings turn off where their default doubts what the opt-in counts.
     *
     * @return list<LooseValue>
     */
    private static function toggled(Toggles $toggles, bool $excluded): array
    {
        $values = [];

        foreach (Toggle::cases() as $toggle) {
            if ($toggle->loosens($toggles->on($toggle), $excluded)) {
                $values[] = LooseValue::of(
                    $toggle->value,
                    self::OFF,
                    $toggle === Toggle::ControlsKills ? self::KILLS : self::TRIALS,
                );
            }
        }

        return $values;
    }

    /**
     * This loose value, where the settings hold it.
     *
     * @return list<LooseValue>
     */
    private static function when(bool $held, LooseValue $value): array
    {
        return $held ? [$value] : [];
    }
}
