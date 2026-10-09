<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * One change a release made to what a config or a baseline writes (ADR-0026,
 * decision 2), applied to the JSON form every format reads into.
 */
interface Step
{
    /** The file with this change made where it applies; as it was where it does not, or cannot be made. */
    public function applied(JsonDocument $file): JsonDocument;

    /** Whether the file still writes what this change retired. */
    public function appliesTo(JsonDocument $file): bool;

    /** Where what it retired is written. */
    public function at(): KeyPath;

    /** What it changed, as a sentence starts: `` `a.b` became `c.d` ``. */
    public function change(): string;

    /** The builder spelling it retires, and what replaces it, where it names one (decision 3). */
    public function spelling(): Spelling|NotGiven;
}
