<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function rtrim;
use function sprintf;

/**
 * A scope's ledger a store could not read, where it may be kept: why, from
 * where, what else the store read beside it, and what else it could not
 * read, for a store that reads more than one place. A ledger that is not there
 * yet is no such thing; it reads as an empty one. A run judges without the
 * ledger it could not read, which costs it a run and never a verdict, and
 * says so.
 */
final readonly class Unreadable
{
    private const string SAID = 'The ledger is unreadable from %s: %s. The run judges without it.';

    private function __construct(
        private UnreadReason $reason,
        private string $from,
        private string $detail,
        private Ledger $besides,
        private Warnings $others,
    ) {
    }

    /** The ledger at this place, unread for this reason, as the detail says. */
    public static function because(UnreadReason $reason, string $from, string $detail): self
    {
        return new self($reason, $from, $detail, Ledger::empty(), Warnings::none());
    }

    /** Why the ledger at this place is not read: it is no ledger this gate reads, or it is larger than one read. */
    public static function notRead(string $from, CannotJudge|TooLarge $why): self
    {
        return self::because(
            $why instanceof TooLarge ? UnreadReason::TooLarge : UnreadReason::Malformed,
            $from,
            $why->why(),
        );
    }

    /** This, with the ledger the store read from another place for the same scope. */
    public function besides(Ledger $ledger): self
    {
        return new self($this->reason, $this->from, $this->detail, $ledger, $this->others);
    }

    /** This, and another place the store could not read either. */
    public function also(self $other): self
    {
        return new self($this->reason, $this->from, $this->detail, $this->besides, $this->others->and($other->said()));
    }

    /** Why, as the store says it. */
    public function detail(): string
    {
        return $this->detail;
    }

    public function reason(): UnreadReason
    {
        return $this->reason;
    }

    /** What the store read of the scope besides: empty for a store that keeps one ledger per scope. */
    public function ledger(): Ledger
    {
        return $this->besides;
    }

    /** One line a run says: where, and why. */
    public function why(): string
    {
        return sprintf(self::SAID, $this->from, rtrim($this->detail, '.'));
    }

    /** What a run says: one warning for each place not read. */
    public function said(): Warnings
    {
        return Warnings::of(Warning::that($this->why()))->and($this->others);
    }
}
