<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a plan expects of one shard: its units' time, by what each unit's
 * estimate rests on, and the opening run its runner pays first.
 */
final readonly class ShardEstimate
{
    /** @param array<string, float> $seconds by basis */
    private function __construct(private array $seconds, private Seconds $opening)
    {
    }

    public static function none(): self
    {
        return new self([], Seconds::of(0.0));
    }

    /** This estimate, and one unit's besides; a unit expected to take no time adds nothing. */
    public function with(Estimated $unit): self
    {
        if ($unit->seconds()->seconds() <= 0.0) {
            return $this;
        }

        $seconds = $this->seconds;
        $basis = $unit->basis()->value;
        $seconds[$basis] = (array_key_exists($basis, $seconds) ? $seconds[$basis] : 0.0) + $unit->seconds()->seconds();

        return new self($seconds, $this->opening);
    }

    /** This estimate, whose runner first pays an opening run this long. */
    public function opening(Seconds $opening): self
    {
        return new self($this->seconds, $opening);
    }

    /** The units' time, every basis together, without the opening run. */
    public function units(): Seconds
    {
        $seconds = 0.0;

        foreach (CostBasis::cases() as $basis) {
            $seconds += $this->of($basis);
        }

        return Seconds::of($seconds);
    }

    /** The units' time that rests on this basis. */
    public function part(CostBasis $basis): Seconds
    {
        return Seconds::of($this->of($basis));
    }

    /** The opening run the shard's runner pays before its units. */
    public function openingRun(): Seconds
    {
        return $this->opening;
    }

    private function of(CostBasis $basis): float
    {
        return array_key_exists($basis->value, $this->seconds) ? $this->seconds[$basis->value] : 0.0;
    }
}
