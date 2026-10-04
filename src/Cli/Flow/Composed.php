<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** What a command runs its flow with: the settings, the adapters they chose, the setup and the reporting. */
final readonly class Composed
{
    public function __construct(
        public Settings $settings,
        public Adapters $adapters,
        public Setup $setup,
        public Reporting $reporting,
    ) {
    }

    /** The same, with a run's budget this long, as `watch` and `pre-push` set their own (ADR-0010, decision 4). */
    public function budgeted(Seconds $budget): self|Invalid
    {
        $settings = Settings::settled(
            $this->settings->effective()->over(Layer::of(Triage::of(budget: $budget))),
            $this->setup->clock->now(),
        );

        return $settings instanceof Settings
            ? new self($settings, $this->adapters, $this->setup, $this->reporting)
            : $settings;
    }

    /**
     * The same, its runs making mutants with the security mutators alone, as
     * `--security` asks (ADR-0021, decision 20); or why there are none to make
     * them with.
     */
    public function securityOnly(): self|CannotJudge
    {
        $adapters = $this->adapters->securityOnly();

        return $adapters instanceof Adapters
            ? new self($this->settings, $adapters, $this->setup, $this->reporting)
            : $adapters;
    }

    /**
     * The same, narrowed as the plan says it was made: to the security
     * mutators, where it was made with `--security`; or why it cannot be.
     */
    public function following(Plan $plan): self|CannotJudge
    {
        return $plan->briefing()->isSecurityOnly() ? $this->securityOnly() : $this;
    }
}
