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
 */
final readonly class LooseValues implements Countable, IteratorAggregate
{
    private const string KILLS = 'a kill counts without its control on the unmutated code';

    private const string NARROWED = 'a kill in a narrowed run counts without its replay, alone run or run again';

    private const string HELD = 'a held unit\'s lines are read from the map, and a line its holding tests reach only'
        . ' after another test\'s state leaves its mutants out of the score';

    private const string TRIALS = 'a mutant left uncovered on a line that is not executable is left out of the score';

    private const string MERGED = 'a pushed tree a merged pull request\'s verdict passed on is not judged again';

    private const string DELTA = 'a merged pull request whose landed tree differs carries what the change from its'
        . ' judged head does not reach';

    private const string OWN_SCOPE = 'a merged pull request\'s results that an earlier commit of it wrote carry onto'
        . ' the default branch';

    private const string LOCK = 'a moved package reaches less than every unit';

    private const string ALONE = 'a kill\'s control runs its killers alone, so a test that fails only after the tests'
        . ' before it counts as a kill';

    private const string CACHE = 'the default branch takes a proof another branch of the repository computed, from the'
        . ' shared cache';

    private const string OFF = 'false';

    private const string TRUST = 'ci.trustMergedPullRequests';

    /** @param list<LooseValue> $values */
    private function __construct(private array $values)
    {
    }

    /** The loose values these settings hold. */
    public static function in(Settings $settings): self
    {
        $excluded = $settings->floors()->uncovered() === Uncovered::Exclude;
        $toggles = $settings->toggles();
        $ci = $settings->ci();
        $reach = $settings->reach();
        $values = [];

        foreach ($toggles->loose($excluded) as $toggle) {
            $values[] = LooseValue::of(
                $toggle->value,
                self::OFF,
                $toggle === Toggle::ControlsKills ? self::KILLS : self::TRIALS,
            );
        }

        $merged = $ci->mergedTrust();
        $values = [
            ...$values,
            ...$settings->effective()->pest()->narrowedKills() === NarrowedKillTrust::Trust
                ? [LooseValue::of('pest.narrowedKills', NarrowedKillTrust::Trust->value, self::NARROWED)]
                : [],
            ...$excluded && $reach->heldCoverage() === HeldCoverageMode::Map
                ? [LooseValue::of('holds.coverage', HeldCoverageMode::Map->value, self::HELD)]
                : [],
            ...$merged->trusts()
                ? [LooseValue::of(
                    self::TRUST,
                    $merged->value,
                    $merged === MergedTrust::Delta ? self::DELTA : self::MERGED,
                )]
                : [],
            ...$ci->trustsMergedPullRequests() && $ci->mergedOwnScopeProofs() === OwnScopeTrust::Trust
                ? [LooseValue::of('ci.mergedOwnScopeProofs', OwnScopeTrust::Trust->value, self::OWN_SCOPE)]
                : [],
            ...$settings->triage()->flaky()->killControls() === KillControl::Alone
                ? [LooseValue::of('flaky.killControls', KillControl::Alone->value, self::ALONE)]
                : [],
            ...$settings->shards()->outbreak()->cache()->trust()->isLoose()
                ? [LooseValue::of('outbreak.cache.trust', CacheTrust::Repository->value, self::CACHE)]
                : [],
            ...$reach->lockMoves() !== LockMoveReach::Everything
                ? [LooseValue::of('reach.lockMoves', $reach->lockMoves()->value, self::LOCK)]
                : [],
        ];

        return new self($values);
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
}
