<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;

/**
 * What asked for each unmutated control, so a shard's measured steps say
 * where each one's time went (ADR-0167): the kinds that asked for it, or one
 * kind for every control none of them asked for.
 *
 * @internal
 */
final readonly class ControlKinds
{
    /** @param array<string, list<ControlKind>> $kinds by each control's key */
    private function __construct(private ControlKind $otherwise, private array $kinds)
    {
    }

    /** Every control asked for by this kind. */
    public static function otherwise(ControlKind $kind): self
    {
        return new self($kind, []);
    }

    /** These, each of these controls asked for by this kind as well. */
    public function asking(ControlKind $kind, Controls $controls): self
    {
        $kinds = $this->kinds;

        foreach ($controls as $control) {
            $kinds[$control->key()][] = $kind;
        }

        return new self($this->otherwise, $kinds);
    }

    /**
     * Where each of these controls' time went, as these runs found it.
     *
     * @return list<ControlTime>
     */
    public function timesOf(Controls $ran, ControlRuns $runs): array
    {
        $times = [];

        foreach ($ran as $control) {
            $key = $control->key();
            $times[] = ControlTime::of(
                $control,
                $runs->of($control),
                ...array_key_exists($key, $this->kinds) ? $this->kinds[$key] : [$this->otherwise],
            );
        }

        return $times;
    }
}
