<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The runner time the gate saved in the savings window, as the summary and
 * the badge show it (ADR-0016, ADR-0017).
 *
 * @internal
 */
final readonly class SavedLately
{
    private function __construct(private Seconds $saved, private SavingsWindow $window)
    {
    }

    public static function of(Seconds $saved, SavingsWindow $window): self
    {
        return new self($saved, $window);
    }

    public function saved(): Seconds
    {
        return $this->saved;
    }

    public function window(): SavingsWindow
    {
        return $this->window;
    }
}
