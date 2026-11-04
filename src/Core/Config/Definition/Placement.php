<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;

/**
 * Where a layer's keys are written: at its top, or in one of its context blocks (ADR-0070), which refuses what
 * the context is told from and what is read before it.
 *
 * @internal
 */
enum Placement
{
    case TopLevel;

    case ContextBlock;

    private const string REFUSED
        = 'expected nothing: a context block cannot set what is read before the context is known';

    /**
     * A key as it is read here: as it is at the top, and refused in a context block.
     *
     * @template T of object|scalar
     *
     * @param  Field<T> $field
     * @return Field<T>
     */
    public function admitting(Field $field): Field
    {
        return $this === self::TopLevel
            ? $field
            : Field::optional($field->key(), Refused::because(self::REFUSED), Effect::DecidesHowTheGateRuns);
    }
}
