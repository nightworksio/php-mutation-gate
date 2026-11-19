<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Port\MapEngine;

/**
 * What a flow hands a map's reading to ask whether its text is vouched for
 * (ADR-0118): the engine's answer, or nothing where the flow has no engine,
 * and every text is read carefully.
 *
 * @internal
 */
final readonly class Vouching
{
    /** @return (Closure(string): MapVouch)|NotGiven */
    public static function by(MapEngine|NotGiven $engine): Closure|NotGiven
    {
        return $engine instanceof MapEngine
            ? static fn(string $json): MapVouch => $engine->vouches(Contents::of($json))
            : $engine;
    }
}
