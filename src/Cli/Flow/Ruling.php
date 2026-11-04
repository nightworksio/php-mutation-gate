<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a shard builds the adapters a group of its units runs with, where the `paths` entries matching them set
 * their own mutators, timeouts or static check (ADR-0074): as the wiring that built the run's own builds them
 * from the group's settings, keeping all but the runner, the analyser and the mutators; or, for a flow composed
 * without it, not at all.
 *
 * @internal
 */
final readonly class Ruling
{
    private const string UNWIRED
        = 'The run was composed without the wiring that builds a runner for the units paths entries match.';

    /** @param (Closure(Settings, Directory): (Adapters|Invalid|CannotJudge))|NotGiven $wiring none runs no group */
    public function __construct(private Closure|NotGiven $wiring = new NotGiven())
    {
    }

    /** @param Closure(Settings, Directory): (Adapters|Invalid|CannotJudge) $wiring builds adapters from settings */
    public static function by(Closure $wiring): self
    {
        return new self($wiring);
    }

    /** These adapters, with what these settings choose for a group of units; or why they cannot be built. */
    public function adapters(Adapters $adapters, Settings $settings): Adapters|Invalid|CannotJudge
    {
        $ruled = $this->wiring instanceof Closure
            ? ($this->wiring)($settings, $adapters->project)
            : CannotJudge::because(self::UNWIRED);

        return $ruled instanceof Adapters ? $adapters->runningAs($ruled) : $ruled;
    }
}
