<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** The budgets of the local modes (ADR-0010): `local.watchBudget` and `local.prePushBudget`. */
final readonly class Local
{
    public function __construct(private Seconds $watchBudget, private Seconds $prePushBudget)
    {
    }

    public function watchBudget(): Seconds
    {
        return $this->watchBudget;
    }

    public function prePushBudget(): Seconds
    {
        return $this->prePushBudget;
    }
}
