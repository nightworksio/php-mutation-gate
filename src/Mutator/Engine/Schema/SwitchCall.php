<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function mb_strstr;
use function sprintf;
use function str_contains;

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
    /** A guard and the switch behind it. */
    private const string BOTH = '(%s && %s)';

    /** A guard, the call behind it, and the original where it is false. */
    private const string EITHER = '(%s ? %s : (%s))';

    private function __construct(private string $switch, private string $valued, private string $guard)
    {
    }

    /** These switches, and no call that takes both values. */
    public static function of(string $switch): self
    {
        return new self($switch, '', '');
    }

    /** These switches, each pure expression's site switched by a call that takes both values. */
    public function valuing(string $valued): self
    {
        return new self($this->switch, $valued, $this->guard);
    }

    /**
     * These switches, each behind this guard, `%d` where it takes the id: a
     * site whose guard is false runs its original and makes no call
     * (ADR-0204).
     */
    public function guarded(string $guard): self
    {
        return new self($this->switch, $this->valued, $guard);
    }

    /** The switch of the site this id names. */
    public function on(int $id): string
    {
        $switch = sprintf($this->switch, $id);

        return $this->guard === '' ? $switch : sprintf(self::BOTH, sprintf($this->guard, $id), $switch);
    }

    /** Whether a pure expression's site is switched by a call that takes both values. */
    public function values(): bool
    {
        return $this->valued !== '';
    }

    /**
     * The call that takes a pure expression's original and mutated code, of
     * the site this id names; behind a guard, the original itself where the
     * guard is false, unless the original holds another site, which would
     * then be written twice more at every level it nests.
     */
    public function valued(int $id, string $original, string $mutated): string
    {
        $valued = sprintf($this->valued, $id, $original, $mutated);
        $opening = (string) mb_strstr($this->guard, '%', before_needle: true);
        $nests = $this->guard !== '' && str_contains($original, $opening);

        return $this->guard === '' || $nests
            ? $valued
            : sprintf(self::EITHER, sprintf($this->guard, $id), $valued, $original);
    }
}
