<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;

/**
 * What one value in a config must be. The validator reads a value through
 * it, the JSON Schema is written from it, and the settings under it declare
 * what they can change, so the three cannot disagree.
 */
interface Node
{
    /** The value written at a path, read into its typed value, how it is shown, and every problem with it. */
    public function read(mixed $value, string $at): Reading;

    /** What the value must be, as a problem says it: `an integer of at least 1`. */
    public function expected(): string;

    /** @return array<string, mixed> the JSON Schema of the value */
    public function schema(): array;

    /** @return array<string, Effect> every setting under this value, by its path from it */
    public function effects(): array;
}
