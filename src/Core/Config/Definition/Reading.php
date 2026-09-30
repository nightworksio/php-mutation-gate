<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_values;

use Closure;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;

/**
 * One value read from a config: its typed value, nothing where it was not
 * written, or every problem found reading it. A value with a problem has no
 * typed value.
 *
 * @template-covariant T of object|scalar = never
 */
final readonly class Reading
{
    /**
     * @phpstan-param T|Absent $value
     *
     * @param list<Problem> $problems
     */
    private function __construct(private object|string|int|float|bool $value, private array $problems)
    {
    }

    /**
     * A value read cleanly.
     *
     * @template U of object|scalar
     *
     * @param  U         $value
     * @return self<U>
     */
    public static function of(object|string|int|float|bool $value): self
    {
        return new self($value, []);
    }

    /**
     * A value nobody wrote.
     *
     * @return self<never>
     */
    public static function nothing(): self
    {
        return new self(Absent::setting(), []);
    }

    /** @return self<never> */
    public static function refused(Problem $problem, Problem ...$more): self
    {
        return new self(Absent::setting(), [$problem, ...array_values($more)]);
    }

    /**
     * A value built from others, or every problem with them.
     *
     * @return self<never>
     */
    public static function invalid(Invalid $invalid): self
    {
        return new self(Absent::setting(), [...$invalid]);
    }

    /**
     * Every problem in these readings, or none.
     *
     * @param self<object|scalar> ...$readings
     */
    public static function problemsIn(self ...$readings): Invalid|Absent
    {
        $problems = [];

        foreach ($readings as $reading) {
            $problems = [...$problems, ...$reading->problems];
        }

        return $problems === [] ? Absent::setting() : Invalid::because(...$problems);
    }

    /**
     * What a builder makes of these readings, or every problem with them, where there is one.
     *
     * @template U of object
     *
     * @param  Closure(): (U|Invalid)                              $build
     * @param  self<object|scalar>            ...$readings
     * @return U|Invalid
     */
    public static function built(Closure $build, self ...$readings): object
    {
        $problems = self::problemsIn(...$readings);

        return $problems instanceof Invalid ? $problems : $build();
    }

    /** @return T|Absent the value, or nothing where it was not written or could not be read */
    public function value(): object|string|int|float|bool
    {
        return $this->value;
    }

    /**
     * The value of a reading that must have one, such as a required setting of an object read without problems.
     *
     * @return T
     */
    public function must(): object|string|int|float|bool
    {
        return $this->value instanceof Absent
            ? throw MisreadSetting::nothingIn('a setting that must be written')
            : $this->value;
    }

    /**
     * This reading's problems, or nothing where it has none, holding no value.
     *
     * @return self<never>
     */
    public function withoutValue(): self
    {
        return new self(Absent::setting(), $this->problems);
    }

    /** @return list<Problem> */
    public function problems(): array
    {
        return $this->problems;
    }
}
