<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff_key;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;

use Closure;

use function count;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\Mutant\Prefix;

/**
 * The replays that vouch for a run's narrowed kills, grouped by the whole
 * order each kill's own run was to take (ADR-0089): of a file's replays,
 * those whose runs were to take the same whole order replay prefixes of it,
 * so one replay to its end, which stands only where every test of the order
 * passes unmutated in it, vouches for every one of them. Only a replay no
 * such replay vouched for runs on its own.
 *
 * @internal
 */
final readonly class WholeOrders
{
    /** Where the file a replay serves and the key of the whole order are joined. */
    private const string BETWEEN = "\n";

    /**
     * @param array<string, PrefixReplay> $replays each replay, by its key
     * @param array<string, Prefix>       $planned the whole order the run of each replay was to take, by the same key
     */
    private function __construct(private array $replays, private array $planned)
    {
    }

    /**
     * @param array<string, PrefixReplay> $replays
     * @param array<string, Prefix>       $planned
     */
    public static function of(array $replays, array $planned): self
    {
        return new self($replays, $planned);
    }

    /**
     * What each replay says, by its key: first each replay to the end of a
     * whole order two or more replays share, all side by side, then each
     * replay none of those vouched for, all side by side.
     *
     * @param  Closure(non-empty-list<PrefixReplay>): list<ReplayVerdict> $replay what each replay says, in order
     * @return array<string, ReplayVerdict>
     */
    public function said(Closure $replay): array
    {
        $said = $this->stood($replay, $this->wholes());
        $left = array_diff_key($this->replays, $said);
        $rest = $left === [] ? [] : $replay(array_values($left));

        foreach (array_keys($left) as $at => $key) {
            $said[$key] = array_key_exists($at, $rest) ? $rest[$at] : ReplayVerdict::NoTime;
        }

        return $said;
    }

    /**
     * Each replay a replay to the end of its whole order stood for, by its key.
     *
     * @param  Closure(non-empty-list<PrefixReplay>): list<ReplayVerdict> $replay
     * @param  list<array{PrefixReplay, non-empty-list<string>}>             $wholes
     * @return array<string, ReplayVerdict>
     */
    private function stood(Closure $replay, array $wholes): array
    {
        $ends = $wholes === [] ? [] : $replay(array_map(static fn(array $whole): PrefixReplay => $whole[0], $wholes));
        $said = [];

        foreach ($wholes as $at => [, $keys]) {
            $stands = array_key_exists($at, $ends) && $ends[$at] === ReplayVerdict::Stands;
            $said += $stands ? array_fill_keys($keys, ReplayVerdict::Stands) : [];
        }

        return $said;
    }

    /**
     * For each whole order two or more replays of one file share, the replay
     * to its end, allowed the longest limit of theirs, and their keys.
     *
     * @return list<array{PrefixReplay, non-empty-list<string>}>
     */
    private function wholes(): array
    {
        $sharing = [];

        foreach ($this->planned as $key => $planned) {
            $order = $planned->key();
            if (is_string($order)) {
                $sharing[implode(self::BETWEEN, [$this->replays[$key]->file()->value(), $order])][] = $key;
            }
        }

        $wholes = [];

        foreach ($sharing as $keys) {
            $whole = $this->replays[$keys[0]]->toTheEnd($this->planned[$keys[0]]);

            foreach (array_slice($keys, 1) as $key) {
                $whole = $whole->alongside($this->replays[$key]);
            }

            $wholes = count($keys) < 2 ? $wholes : [...$wholes, [$whole, $keys]];
        }

        return $wholes;
    }
}
