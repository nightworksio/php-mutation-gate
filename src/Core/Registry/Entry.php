<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Registry;

use NightWorksIO\MutationGate\Core\Config\Name;

/**
 * One thing an extension registered: its name, the package that registered
 * it, and the thing itself.
 *
 * @template-covariant T of object
 */
final readonly class Entry
{
    /** @param T $value */
    private function __construct(private Name $name, private Origin $origin, private object $value)
    {
    }

    /**
     * @template U of object
     *
     * @param  U       $value
     * @return self<U>
     */
    public static function of(Name $name, Origin $origin, object $value): self
    {
        return new self($name, $origin, $value);
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    /** @return T */
    public function value(): object
    {
        return $this->value;
    }
}
