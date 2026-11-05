<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\PathRules;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * A unit, the test files the runner says can judge it, or why it cannot say, and what the `paths` entries that
 * match it set that affects results, which is nothing where none does (ADR-0074).
 *
 * @internal
 */
final readonly class Judging
{
    private function __construct(private Unit $unit, private Paths|CannotJudge $judges, private string $rules)
    {
    }

    public static function of(Unit $unit, Paths|CannotJudge $judges): self
    {
        return new self($unit, $judges, '');
    }

    /** This unit, run under what the `paths` entries that match it set, as `MatchedRules::key()` spells it. */
    public function ruledBy(string $rules): self
    {
        return new self($this->unit, $this->judges, $rules);
    }

    /**
     * What the unit's key reads of the `paths` entries that match it: nothing where none sets anything that
     * affects results, so its key is the one it would be without `paths`.
     *
     * @return list<string>
     */
    public function ruling(): array
    {
        return $this->rules === '' ? [] : [PathRules::KEY, $this->rules];
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function judges(): Paths|CannotJudge
    {
        return $this->judges;
    }
}
