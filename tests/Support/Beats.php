<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Heartbeat;

/** The beats a mutant's own process gave, kept rather than written to its error output. */
final class Beats
{
    private string $kept = '';

    public function heartbeat(): Heartbeat
    {
        return Heartbeat::through(function (string $beat): void {
            $this->kept .= $beat;
        });
    }

    /** Every beat, in order. */
    public function kept(): string
    {
        return $this->kept;
    }
}
