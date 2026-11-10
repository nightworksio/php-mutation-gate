<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

/**
 * One judged mutant, as `watch` tells every client of it (ADR-0146,
 * ADR-0133): its id, its site, what was judged, the test that killed it,
 * whether the round was quick, its unit and the digest of the unit it was
 * judged against.
 */
final readonly class MutantEvent
{
    private const string SITE = '%s:%d';

    private function __construct(
        private Mutant $mutant,
        private EventStatus $status,
        private bool $quick,
        private Digest|NotGiven $digest,
    ) {
    }

    /** The event of a mutant judged against its unit's file of this digest; nothing of one no run judges. */
    public static function of(Mutant $mutant, bool $quick, Digest|NotGiven $digest): self|NotGiven
    {
        $status = EventStatus::of($mutant->status());

        return $status instanceof EventStatus ? new self($mutant, $status, $quick, $digest) : $status;
    }

    /** The event as one line of JSON. */
    public function line(): string
    {
        $location = $this->mutant->location();
        $killer = $this->mutant->killers()->first();

        return JsonText::compact([
            'mutant' => $this->mutant->id()->value(),
            'site' => sprintf(self::SITE, $location->file()->value(), $location->start()->number()),
            'status' => $this->status->value,
            'by' => $killer instanceof TestId ? $killer->value() : null,
            'quick' => $this->quick,
            'unit' => $location->file()->value(),
            'digest' => $this->digest instanceof Digest ? $this->digest->value() : null,
        ]);
    }
}
