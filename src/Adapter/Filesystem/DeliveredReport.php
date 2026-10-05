<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * A reporter whose sending needs a credential, under `--deliver-later` (ADR-0007 decision 5): what it would send is
 * added to the run's delivery, for `deliver` to send.
 */
final readonly class DeliveredReport implements Reporter
{
    private function __construct(private Deferring $reporter, private DeliveryDirectory $delivery)
    {
    }

    /** This reporter's payloads, added to this delivery. */
    public static function of(Deferring $reporter, DeliveryDirectory $delivery): self
    {
        return new self($reporter, $delivery);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $reporter = $this->reporter;
        $written = $this->delivery->adding(
            static fn(Delivery $delivery): Delivery => $reporter->deferred($verdict, $delivery),
        );

        return $written instanceof Written ? $written : NotWritten::because($written->why());
    }
}
