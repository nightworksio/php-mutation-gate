<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Score\Percentage;

use function sprintf;

/**
 * When `config:suggest` proposes a change (ADR-0076): `suggest`, which the
 * `Reports` part holds, as it reports and never runs. A mutator is idle when
 * enough of its mutants were judged, too few survived and it takes a large
 * enough share of every mutator's time; it is noisy when enough of its
 * mutants were made and most of them were ignored or equivalent; and a
 * directory is slow when enough of its mutants ran out of time.
 */
final readonly class Suggest
{
    private const int IDLE_JUDGED = 500;

    private const int IDLE_SURVIVORS = 0;

    private const int IDLE_SHARE = 5;

    private const int IGNORED_SHARE = 80;

    private const int IGNORED_MUTANTS = 50;

    private const int TIMEOUT_CLUSTER = 3;

    private function __construct(
        private int|Absent $idleJudged,
        private int|Absent $idleSurvivors,
        private int|float|Absent $idleShare,
        private int|float|Absent $ignoredShare,
        private int|Absent $ignoredMutants,
        private int|Absent $timeoutCluster,
    ) {
    }

    public static function of(
        int|Absent $idleJudged = new Absent(),
        int|Absent $idleSurvivors = new Absent(),
        int|float|Absent $idleShare = new Absent(),
        int|float|Absent $ignoredShare = new Absent(),
        int|Absent $ignoredMutants = new Absent(),
        int|Absent $timeoutCluster = new Absent(),
    ): self {
        return new self($idleJudged, $idleSurvivors, $idleShare, $ignoredShare, $ignoredMutants, $timeoutCluster);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->idleJudged, $later->idleJudged),
            Absent::laid($this->idleSurvivors, $later->idleSurvivors),
            Absent::laid($this->idleShare, $later->idleShare),
            Absent::laid($this->ignoredShare, $later->ignoredShare),
            Absent::laid($this->ignoredMutants, $later->ignoredMutants),
            Absent::laid($this->timeoutCluster, $later->timeoutCluster),
        );
    }

    /** `suggest.idle.judged`: how many of a mutator's mutants must be judged before it can be idle. */
    public function idleJudged(): int
    {
        return is_int($this->idleJudged) ? $this->idleJudged : self::IDLE_JUDGED;
    }

    /** `suggest.idle.survivors`: the most survivors an idle mutator has. */
    public function idleSurvivors(): int
    {
        return is_int($this->idleSurvivors) ? $this->idleSurvivors : self::IDLE_SURVIVORS;
    }

    /** `suggest.idle.share`: the least share of every mutator's time an idle mutator takes. */
    public function idleShare(): Percentage
    {
        return $this->percentage($this->idleShare, self::IDLE_SHARE);
    }

    /** `suggest.ignored.share`: the least share of a noisy mutator's mutants that are ignored or equivalent. */
    public function ignoredShare(): Percentage
    {
        return $this->percentage($this->ignoredShare, self::IGNORED_SHARE);
    }

    /** `suggest.ignored.mutants`: how many mutants a mutator must make before it can be noisy. */
    public function ignoredMutants(): int
    {
        return is_int($this->ignoredMutants) ? $this->ignoredMutants : self::IGNORED_MUTANTS;
    }

    /** `suggest.timeouts.cluster`: how many mutants of one directory must run out of time before it is slow. */
    public function timeoutCluster(): int
    {
        return is_int($this->timeoutCluster) ? $this->timeoutCluster : self::TIMEOUT_CLUSTER;
    }

    /** The `suggest` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        $idle = Json::object(
            Member::of('judged', $this->idleJudged),
            Member::of('survivors', $this->idleSurvivors),
            Member::of('share', $this->idleShare),
        );
        $ignored = Json::object(
            Member::of('share', $this->ignoredShare),
            Member::of('mutants', $this->ignoredMutants),
        );
        $timeouts = Json::object(Member::of('cluster', $this->timeoutCluster));

        $suggest = Json::object(
            Member::unlessEmpty('idle', $idle),
            Member::unlessEmpty('ignored', $ignored),
            Member::unlessEmpty('timeouts', $timeouts),
        );

        return Member::unlessEmpty('suggest', $suggest);
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        $calls = [
            'idleJudged' => $this->idleJudged,
            'idleSurvivors' => $this->idleSurvivors,
            'idleShare' => $this->idleShare,
            'ignoredShare' => $this->ignoredShare,
            'ignoredMutants' => $this->ignoredMutants,
            'timeoutCluster' => $this->timeoutCluster,
        ];
        $written = [];

        foreach ($calls as $method => $value) {
            $written = $value instanceof Absent
                ? $written
                : [...$written, sprintf('Suggest::%s(%s)', $method, PhpCalls::literal($value))];
        }

        return PhpCalls::inWith(...$written);
    }

    private function percentage(int|float|Absent $set, int $standard): Percentage
    {
        $parsed = Percentage::parse($set instanceof Absent ? $standard : $set);

        return $parsed instanceof Percentage ? $parsed : Percentage::none();
    }
}
