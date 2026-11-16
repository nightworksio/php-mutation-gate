<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function sprintf;

/**
 * The calls a schema's sites make (ADR-0091): a switch that says whether a
 * site's mutant is in force, `%d` where it takes the site's id, and, where a
 * split stream asks for its change check (ADR-0117), a call that takes a
 * pure expression's original and mutated code as closures, `%1$d` where it
 * takes the id, `%2$s` the original and `%3$s` the mutated code.
 *
 * @internal the engine's own
 */
final readonly class SwitchCall
{
    private function __construct(private string $switch, private string $valued)
    {
    }

    /** These switches, and no call that takes both values. */
    public static function of(string $switch): self
    {
        return new self($switch, '');
    }

    /** These switches, each pure expression's site switched by a call that takes both values. */
    public function valuing(string $valued): self
    {
        return new self($this->switch, $valued);
    }

    /** The switch of the site this id names. */
    public function on(int $id): string
    {
        return sprintf($this->switch, $id);
    }

    /** Whether a pure expression's site is switched by a call that takes both values. */
    public function values(): bool
    {
        return $this->valued !== '';
    }

    /** The call that takes a pure expression's original and mutated code, of the site this id names. */
    public function valued(int $id, string $original, string $mutated): string
    {
        return sprintf($this->valued, $id, $original, $mutated);
    }
}
