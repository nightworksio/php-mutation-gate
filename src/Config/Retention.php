<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\AllKept;
use NightWorksIO\MutationGate\Core\Format\Json;

/** What a ledger keeps and how large what the gate reads may be: `proofs.keep` and `proofs.limits`. */
final readonly class Retention implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `proofs.keep.killers`: how many mutants keep their killers at most. */
    public static function killers(int $mutants): self
    {
        return new self(Json::at('proofs.keep.killers', $mutants));
    }

    /** `proofs.keep.killers: all`: the byte limits alone cap them, the default. */
    public static function allKillers(): self
    {
        return new self(Json::at('proofs.keep.killers', AllKept::All->value));
    }

    /** `proofs.keep.bases`: how many of the most recently seen bases keep their proofs; 5. */
    public static function bases(int $bases): self
    {
        return new self(Json::at('proofs.keep.bases', $bases));
    }

    /** `proofs.keep.proofs`: how many proofs are kept at most, the newest; 20,000. */
    public static function proofs(int $proofs): self
    {
        return new self(Json::at('proofs.keep.proofs', $proofs));
    }

    /** `proofs.keep.functions`: how many functions keep their killers at most; 5,000. */
    public static function functions(int $functions): self
    {
        return new self(Json::at('proofs.keep.functions', $functions));
    }

    /** `proofs.limits.ledger.packed`: the most compressed bytes of a ledger; 11,000,000. */
    public static function ledgerPacked(int $bytes): self
    {
        return new self(Json::at('proofs.limits.ledger.packed', $bytes));
    }

    /** `proofs.limits.ledger.unpacked`: the most decompressed bytes of a ledger; 38,000,000. */
    public static function ledgerUnpacked(int $bytes): self
    {
        return new self(Json::at('proofs.limits.ledger.unpacked', $bytes));
    }

    /** `proofs.limits.map.packed`: the most compressed bytes of a kept coverage map; 4,200,000. */
    public static function mapPacked(int $bytes): self
    {
        return new self(Json::at('proofs.limits.map.packed', $bytes));
    }

    /** `proofs.limits.map.unpacked`: the most decompressed bytes of a kept coverage map; 85,000,000. */
    public static function mapUnpacked(int $bytes): self
    {
        return new self(Json::at('proofs.limits.map.unpacked', $bytes));
    }

    /** `proofs.limits.handoff.packed`: the most compressed bytes of a map one job hands another; 256,000,000. */
    public static function handoffPacked(int $bytes): self
    {
        return new self(Json::at('proofs.limits.handoff.packed', $bytes));
    }

    /** `proofs.limits.handoff.unpacked`: the most decompressed bytes of a handed map; what memory holds by default. */
    public static function handoffUnpacked(int $bytes): self
    {
        return new self(Json::at('proofs.limits.handoff.unpacked', $bytes));
    }

    /** `proofs.keep.kills`: how many kills the learned timeout multiple is read from, the newest. */
    public static function kills(int $kills): self
    {
        return new self(Json::at('proofs.keep.kills', $kills));
    }

    /** `proofs.keep.runs`: how many runs the learned shard count is read from, the newest. */
    public static function runs(int $runs): self
    {
        return new self(Json::at('proofs.keep.runs', $runs));
    }

    /** `proofs.limits.read`: the longest a read of a ledger over a network may take. */
    public static function read(string $duration): self
    {
        return new self(Json::at('proofs.limits.read', $duration));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
