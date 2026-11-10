<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Whether Outbreak's core, the `outbreak` binary, takes the bulk of the
 * coverage maps a plan hands its shards, and of the map a run's report is
 * converted into, off the gate's PHP, which binary, how long one run of it
 * may take and on how many threads it writes (ADR-0118, ADR-0119), and
 * where `outbreak cache` keeps proofs by their keys (ADR-0131), and whether
 * `outbreak.so` proves a mutant equivalent before it runs and rechecks a
 * stream's kill by a test that touched nondeterminism (ADR-0130), and where
 * its static graph acts (ADR-0132), and whether `outbreak serve` holds the
 * project's state (ADR-0133): `outbreak`, which the `Shards` part
 * holds. Only `outbreak.equivalence` and `outbreak.recheckTouchedKills`
 * change a result, so only they are in a proof's key: the binary's output is
 * the gate's own, a cached proof stands under its key as a ledger's does, and
 * the graph decides what a run reaches, as a `reach` setting does.
 */
final readonly class Outbreak
{
    /** The key the Pest runner's options hold these settings under. */
    public const string OPTION = 'outbreak';

    /** The key of `outbreak.enabled`. */
    public const string ENABLED = 'enabled';

    /** The key of `outbreak.binary`. */
    public const string BINARY = 'binary';

    /** The key of `outbreak.timeout`. */
    public const string TIMEOUT = 'timeout';

    /** The key of `outbreak.threads`. */
    public const string THREADS = 'threads';

    /** The key of `outbreak.equivalence`. */
    public const string EQUIVALENCE = 'equivalence';

    /** The key of `outbreak.forkStates`. */
    public const string FORK_STATES = 'forkStates';

    /** The key of `outbreak.recheckTouchedKills`. */
    public const string RECHECK_TOUCHED_KILLS = 'recheckTouchedKills';

    /** The key of `outbreak.graph`. */
    public const string GRAPH = 'graph';

    /** The minutes one run of the binary may take, where the config does not say. */
    private const int MINUTES = 5;

    private function __construct(
        private bool|Absent $enabled,
        private string|Absent $binary,
        private Seconds|Absent $timeout,
        private int|Absent $threads,
        private OutbreakCache $cache,
        private bool|Absent $equivalence,
        private bool|Absent $forkStates,
        private bool|Absent $recheckTouchedKills,
        private GraphUse|Absent $graph,
        private OutbreakServe $serve,
    ) {
    }

    public static function of(
        bool|Absent $enabled = new Absent(),
        string|Absent $binary = new Absent(),
        Seconds|Absent $timeout = new Absent(),
        int|Absent $threads = new Absent(),
        OutbreakCache|Absent $cache = new Absent(),
        bool|Absent $equivalence = new Absent(),
        bool|Absent $forkStates = new Absent(),
        bool|Absent $recheckTouchedKills = new Absent(),
        GraphUse|Absent $graph = new Absent(),
        OutbreakServe|Absent $serve = new Absent(),
    ): self {
        return new self(
            $enabled,
            $binary,
            $timeout,
            $threads,
            $cache instanceof OutbreakCache ? $cache : OutbreakCache::of(),
            $equivalence,
            $forkStates,
            $recheckTouchedKills,
            $graph,
            $serve instanceof OutbreakServe ? $serve : OutbreakServe::of(),
        );
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->enabled, $later->enabled),
            Absent::laid($this->binary, $later->binary),
            Absent::laid($this->timeout, $later->timeout),
            Absent::laid($this->threads, $later->threads),
            $this->cache->over($later->cache),
            Absent::laid($this->equivalence, $later->equivalence),
            Absent::laid($this->forkStates, $later->forkStates),
            Absent::laid($this->recheckTouchedKills, $later->recheckTouchedKills),
            Absent::laid($this->graph, $later->graph),
            $this->serve->over($later->serve),
        );
    }

    /**
     * `outbreak.enabled`: whether the gate runs the binary where one fits the
     * machine and passes its checksum; true by default.
     */
    public function enabled(): bool
    {
        return $this->enabled instanceof Absent || $this->enabled;
    }

    /**
     * `outbreak.binary`: the binary to run, from the project's root where it
     * is relative, in place of the one the package ships; none by default.
     */
    public function binary(): string|NotGiven
    {
        return $this->binary instanceof Absent ? NotGiven::value() : $this->binary;
    }

    /**
     * `outbreak.timeout`: how long one run of the binary may take before it is
     * stopped and the gate does the work itself; five minutes by default.
     */
    public function timeout(): Seconds
    {
        return $this->timeout instanceof Seconds ? $this->timeout : Seconds::minutes(self::MINUTES);
    }

    /**
     * `outbreak.threads`: how many threads the binary writes a plan's maps on;
     * none by default, for as many as the machine has cores.
     */
    public function threads(): int|NotGiven
    {
        return $this->threads instanceof Absent ? NotGiven::value() : $this->threads;
    }

    /**
     * `outbreak.equivalence`: whether `outbreak.so`, where it loads into the
     * tests' PHP, proves a mutant whose op arrays are its original's
     * equivalent before it runs (ADR-0130); true by default.
     */
    public function equivalence(): bool
    {
        return $this->equivalence instanceof Absent || $this->equivalence;
    }

    /**
     * `outbreak.forkStates`: whether a split stream loads `outbreak.so` and
     * counts the forks whose call returned the same state as its parent's
     * (H5), a measurement no verdict reads; false by default.
     */
    public function forkStates(): bool
    {
        return $this->forkStates === true;
    }

    /**
     * `outbreak.recheckTouchedKills`: whether a split stream's kill by a test
     * that touched the clock, randomness, the environment, another process,
     * the network or a file outside the project after its fork, as
     * `outbreak.so` sees it, is judged again by the mutant's own run; true by
     * default, and false is loose.
     */
    public function recheckTouchedKills(): bool
    {
        return $this->recheckTouchedKills instanceof Absent || $this->recheckTouchedKills;
    }

    /**
     * `outbreak.graph`: where the static graph acts; `local` by default, in
     * `watch`'s quick rounds alone, and `narrow` is loose.
     */
    public function graph(): GraphUse
    {
        return $this->graph instanceof GraphUse ? $this->graph : GraphUse::Local;
    }

    /** `outbreak.serve`: whether `outbreak serve` holds the project's state, and its clocks (ADR-0133). */
    public function serve(): OutbreakServe
    {
        return $this->serve;
    }

    /** `outbreak.cache`: where `outbreak cache` keeps proofs by their keys, and which packs are read (ADR-0131). */
    public function cache(): OutbreakCache
    {
        return $this->cache;
    }

    /**
     * The runner a config chooses, with these settings beneath the options it
     * gives the Pest runner, which reads the plan's whole map in each shard
     * with the binary; any other runner as it is.
     */
    public function beneath(Choice $runner): Choice
    {
        $options = Json::object(Member::of(
            self::OPTION,
            Json::object(
                Member::of(self::ENABLED, $this->enabled()),
                Member::of(self::BINARY, $this->binary),
                Member::of(self::TIMEOUT, $this->timeout()->seconds()),
                Member::of(self::THREADS, $this->threads),
                Member::of(self::EQUIVALENCE, $this->equivalence()),
                Member::of(self::FORK_STATES, $this->forkStates()),
                Member::of(self::RECHECK_TOUCHED_KILLS, $this->recheckTouchedKills()),
                $this->cache->option(),
            ),
        ));

        return $runner->use()->value() === BuiltinRunner::Pest->value
            ? Choice::of($runner->use()->value(), $runner->options()->over($options))
            : $runner;
    }

    public function written(): Json
    {
        return Json::object(Member::unlessEmpty(
            self::OPTION,
            Json::object(
                Member::of(self::ENABLED, $this->enabled),
                Member::of(self::BINARY, $this->binary),
                Member::of(self::TIMEOUT, WholeSeconds::duration($this->timeout)),
                Member::of(self::THREADS, $this->threads),
                Member::of(self::EQUIVALENCE, $this->equivalence),
                Member::of(self::FORK_STATES, $this->forkStates),
                Member::of(self::RECHECK_TOUCHED_KILLS, $this->recheckTouchedKills),
                Member::of(self::GRAPH, $this->graph instanceof GraphUse ? $this->graph->value : $this->graph),
                $this->serve->written(),
                $this->cache->written(),
            ),
        ));
    }

    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...match ($this->enabled) {
                true => ['Outbreak::on()'],
                false => ['Outbreak::off()'],
                default => [],
            },
            ...$this->binary instanceof Absent
                ? []
                : [sprintf('Outbreak::binary(%s)', PhpCalls::literal($this->binary))],
            ...$this->timeout instanceof Seconds
                ? [sprintf('Outbreak::timeout(%s)', PhpCalls::literal($this->timeout->written()))]
                : [],
            ...$this->threads instanceof Absent ? [] : [sprintf('Outbreak::threads(%d)', $this->threads)],
            ...match ($this->equivalence) {
                true => ['Outbreak::equivalenceOn()'],
                false => ['Outbreak::equivalenceOff()'],
                default => [],
            },
            ...match ($this->forkStates) {
                true => ['Outbreak::forkStatesCounted()'],
                false => ['Outbreak::forkStatesUncounted()'],
                default => [],
            },
            ...match ($this->recheckTouchedKills) {
                true => ['Outbreak::touchedKillsRechecked()'],
                false => ['Outbreak::touchedKillsTrusted()'],
                default => [],
            },
            ...$this->graph instanceof GraphUse ? [$this->graph->php()] : [],
            ...$this->serve->php(),
            ...$this->cache->php(),
        ]);
    }
}
