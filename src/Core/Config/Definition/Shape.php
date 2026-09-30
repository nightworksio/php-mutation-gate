<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * What one value in a config must be. A config is read through it, the JSON
 * Schema is written from it, and the settings under it declare what they can
 * change, so the three cannot disagree.
 *
 * @template-covariant T of object|scalar
 */
interface Shape
{
    /**
     * The value at a place in a config, read into its typed value, or every problem with it.
     *
     * @return Reading<T>
     */
    public function read(Node $at): Reading;

    /** What the value must be, as a problem says it: `an integer of at least 1`. */
    public function expected(): string;

    /** The JSON Schema of the value. */
    public function schema(): Json;

    /** @return array<string, Effect> every setting under this value, by its path from it */
    public function effects(): array;
}
