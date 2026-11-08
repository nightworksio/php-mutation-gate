<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerRetention;
use NightWorksIO\MutationGate\Core\Proof\LimitHistory;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use const PHP_INT_MAX;

use function sprintf;

/**
 * What a ledger keeps and how large what the gate reads may be: `proofs.keep` and `proofs.limits`, which the `Proofs`
 * part holds. A byte limit trims a ledger's oldest proofs where it is written, and refuses what is past it where it
 * is read.
 */
final readonly class Retained
{
    private function __construct(
        private int|AllKept|Absent $killers,
        private int|Absent $bases,
        private int|Absent $proofs,
        private int|Absent $functions,
        private int|Absent $ledgerPacked,
        private int|Absent $ledgerUnpacked,
        private int|Absent $mapPacked,
        private int|Absent $mapUnpacked,
        private int|Absent $handoffPacked,
        private int|Absent $handoffUnpacked,
        private int|Absent $kills,
        private int|Absent $runs,
        private Seconds|Absent $read,
    ) {
    }

    public static function of(
        int|AllKept|Absent $killers = new Absent(),
        int|Absent $bases = new Absent(),
        int|Absent $proofs = new Absent(),
        int|Absent $functions = new Absent(),
        int|Absent $ledgerPacked = new Absent(),
        int|Absent $ledgerUnpacked = new Absent(),
        int|Absent $mapPacked = new Absent(),
        int|Absent $mapUnpacked = new Absent(),
        int|Absent $handoffPacked = new Absent(),
        int|Absent $handoffUnpacked = new Absent(),
        int|Absent $kills = new Absent(),
        int|Absent $runs = new Absent(),
        Seconds|Absent $read = new Absent(),
    ): self {
        return new self(
            $killers,
            $bases,
            $proofs,
            $functions,
            $ledgerPacked,
            $ledgerUnpacked,
            $mapPacked,
            $mapUnpacked,
            $handoffPacked,
            $handoffUnpacked,
            $kills,
            $runs,
            $read,
        );
    }

    /** Every key at the value it takes where every layer leaves it out, but a hand-off's unpacked bytes. */
    public static function standard(): self
    {
        return self::of(
            AllKept::All,
            LedgerRetention::BASES,
            LedgerRetention::PROOFS,
            LedgerRetention::FUNCTIONS,
            LedgerLimits::PACKED,
            LedgerLimits::UNPACKED,
            MapLimits::PACKED,
            MapLimits::UNPACKED,
            HandoffLimits::PACKED,
            kills: LimitHistory::KILLS,
            runs: LimitHistory::RUNS,
            read: Seconds::of(LedgerLimits::SECONDS),
        );
    }

    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->killers, $later->killers),
            Absent::laid($this->bases, $later->bases),
            Absent::laid($this->proofs, $later->proofs),
            Absent::laid($this->functions, $later->functions),
            Absent::laid($this->ledgerPacked, $later->ledgerPacked),
            Absent::laid($this->ledgerUnpacked, $later->ledgerUnpacked),
            Absent::laid($this->mapPacked, $later->mapPacked),
            Absent::laid($this->mapUnpacked, $later->mapUnpacked),
            Absent::laid($this->handoffPacked, $later->handoffPacked),
            Absent::laid($this->handoffUnpacked, $later->handoffUnpacked),
            Absent::laid($this->kills, $later->kills),
            Absent::laid($this->runs, $later->runs),
            Absent::laid($this->read, $later->read),
        );
    }

    /** `proofs.keep.killers`: how many mutants keep their killers at most; all of them by default. */
    public function killers(): int
    {
        return is_int($this->killers) ? $this->killers : PHP_INT_MAX;
    }

    public function bases(): int
    {
        return $this->bases instanceof Absent ? LedgerRetention::BASES : $this->bases;
    }

    public function proofs(): int
    {
        return $this->proofs instanceof Absent ? LedgerRetention::PROOFS : $this->proofs;
    }

    public function functions(): int
    {
        return $this->functions instanceof Absent ? LedgerRetention::FUNCTIONS : $this->functions;
    }

    public function ledgerPacked(): int
    {
        return $this->ledgerPacked instanceof Absent ? LedgerLimits::PACKED : $this->ledgerPacked;
    }

    public function ledgerUnpacked(): int
    {
        return $this->ledgerUnpacked instanceof Absent ? LedgerLimits::UNPACKED : $this->ledgerUnpacked;
    }

    public function mapPacked(): int
    {
        return $this->mapPacked instanceof Absent ? MapLimits::PACKED : $this->mapPacked;
    }

    public function mapUnpacked(): int
    {
        return $this->mapUnpacked instanceof Absent ? MapLimits::UNPACKED : $this->mapUnpacked;
    }

    /** `proofs.limits.handoff.packed`, where a layer sets it. */
    public function handoffPacked(): int|Absent
    {
        return $this->handoffPacked;
    }

    /** `proofs.limits.handoff.unpacked`, where a layer sets it; what the process's memory holds where none does. */
    public function handoffUnpacked(): int|Absent
    {
        return $this->handoffUnpacked;
    }

    /** `proofs.keep.kills`: how many kills the learned timeout multiple is read from, the newest. */
    public function kills(): int
    {
        return $this->kills instanceof Absent ? LimitHistory::KILLS : $this->kills;
    }

    /** `proofs.keep.runs`: how many runs the learned shard count is read from, the newest. */
    public function runs(): int
    {
        return $this->runs instanceof Absent ? LimitHistory::RUNS : $this->runs;
    }

    /** `proofs.limits.read`: the longest a read of a ledger over a network may take. */
    public function read(): Seconds
    {
        return $this->read instanceof Seconds ? $this->read : Seconds::of(LedgerLimits::SECONDS);
    }

    /** The `proofs` section with `keep` and `limits` in it. */
    public function into(Json $proofs): Json
    {
        return $proofs
            ->with(Member::unlessEmpty(
                'keep',
                Json::object(
                    Member::of('killers', $this->killers instanceof AllKept ? $this->killers->value : $this->killers),
                    Member::of('bases', $this->bases),
                    Member::of('proofs', $this->proofs),
                    Member::of('functions', $this->functions),
                    Member::of('kills', $this->kills),
                    Member::of('runs', $this->runs),
                ),
            ))
            ->with(Member::unlessEmpty(
                'limits',
                Json::object(
                    $this->bytes('ledger', $this->ledgerPacked, $this->ledgerUnpacked),
                    $this->bytes('map', $this->mapPacked, $this->mapUnpacked),
                    $this->bytes('handoff', $this->handoffPacked, $this->handoffUnpacked),
                    Member::of('read', WholeSeconds::duration($this->read)),
                ),
            ));
    }

    /** The builder's calls that set these keys, where a layer sets them. */
    public function php(): PhpCalls
    {
        $called = static fn(string $call, int|Absent $value): array => $value instanceof Absent
            ? []
            : [sprintf($call, $value)];

        return PhpCalls::inWith(...[
            ...match (true) {
                $this->killers instanceof AllKept => ['Retention::allKillers()'],
                $this->killers instanceof Absent => [],
                default => [sprintf('Retention::killers(%d)', $this->killers)],
            },
            ...$called('Retention::bases(%d)', $this->bases),
            ...$called('Retention::proofs(%d)', $this->proofs),
            ...$called('Retention::functions(%d)', $this->functions),
            ...$called('Retention::ledgerPacked(%d)', $this->ledgerPacked),
            ...$called('Retention::ledgerUnpacked(%d)', $this->ledgerUnpacked),
            ...$called('Retention::mapPacked(%d)', $this->mapPacked),
            ...$called('Retention::mapUnpacked(%d)', $this->mapUnpacked),
            ...$called('Retention::handoffPacked(%d)', $this->handoffPacked),
            ...$called('Retention::handoffUnpacked(%d)', $this->handoffUnpacked),
            ...$called('Retention::kills(%d)', $this->kills),
            ...$called('Retention::runs(%d)', $this->runs),
            ...$this->read instanceof Seconds
                ? [sprintf('Retention::read(%s)', PhpCalls::literal($this->read->written()))]
                : [],
        ]);
    }

    private function bytes(string $key, int|Absent $packed, int|Absent $unpacked): Member
    {
        return Member::unlessEmpty(
            $key,
            Json::object(Member::of('packed', $packed), Member::of('unpacked', $unpacked)),
        );
    }
}
