<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Proof\Compression;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;

use function sprintf;

/**
 * How a ledger's file is compressed (ADR-0085): `proofs.compression`, zstd
 * where this machine offers it and gzip where not by default, and
 * `proofs.compressionLevel`, which the `Proofs` part holds. Each judges or
 * reports only: it changes the bytes of the file, never what it holds.
 *
 * @internal
 */
final readonly class Compressed
{
    private function __construct(private Compression|Absent $compression, private int|Absent $level)
    {
    }

    public static function of(Compression|Absent $compression = new Absent(), int|Absent $level = new Absent()): self
    {
        return new self($compression, $level);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->compression, $later->compression),
            Absent::laid($this->level, $later->level),
        );
    }

    public function compression(): Compression
    {
        return $this->compression instanceof Compression ? $this->compression : Compression::Auto;
    }

    public function level(): int
    {
        return is_int($this->level) ? $this->level : LedgerCodec::LEVEL;
    }

    /** @return list<Member> the members a config writes, each of a key it sets */
    public function members(): array
    {
        $compression = $this->compression instanceof Compression ? $this->compression->value : $this->compression;

        return [Member::of('compression', $compression), Member::of('compressionLevel', $this->level)];
    }

    /** @return list<string> the builder's calls for the keys it sets */
    public function calls(): array
    {
        return [
            ...$this->compression instanceof Compression
                ? [sprintf('Proofs::compression(%s)', PhpCalls::literal($this->compression->value))]
                : [],
            ...is_int($this->level) ? [sprintf('Proofs::compressionLevel(%d)', $this->level)] : [],
        ];
    }
}
