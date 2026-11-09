<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * `flaky`: whether each survivor is run once more before it counts, and what
 * a kill's unmutated control runs (ADR-0008, ADR-0143).
 *
 * @internal
 */
final readonly class FlakySetting
{
    private function __construct(private bool|Absent $confirmSurvivors, private KillControl|Absent $killControls)
    {
    }

    public static function of(bool|Absent $confirmSurvivors, KillControl|Absent $killControls): self
    {
        return new self($confirmSurvivors, $killControls);
    }

    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->confirmSurvivors, $later->confirmSurvivors),
            Absent::laid($this->killControls, $later->killControls),
        );
    }

    /** `flaky.confirmSurvivors`: whether each survivor is run once more before it counts. */
    public function confirmSurvivors(): bool
    {
        return $this->confirmSurvivors instanceof Absent || $this->confirmSurvivors;
    }

    /** `flaky.killControls`: what a kill's unmutated control runs. */
    public function killControls(): KillControl
    {
        return $this->killControls instanceof KillControl ? $this->killControls : KillControl::Order;
    }

    public function written(): Json
    {
        return Json::object(
            Member::of('confirmSurvivors', $this->confirmSurvivors),
            Member::of(
                'killControls',
                $this->killControls instanceof KillControl ? $this->killControls->value : $this->killControls,
            ),
        );
    }

    /** The builder's calls for what a layer sets. */
    public function calls(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->confirmSurvivors instanceof Absent ? [] : [
                $this->confirmSurvivors ? 'Flaky::confirmingSurvivors()' : 'Flaky::notConfirmingSurvivors()',
            ],
            ...$this->killControls instanceof KillControl ? [$this->killControls->php()] : [],
        ]);
    }
}
