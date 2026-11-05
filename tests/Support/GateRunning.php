<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\File\Path;

/** CI definitions that run the gate, as a test names them. */
final class GateRunning
{
    /** A definition at each of these paths, each holding this text. */
    public static function at(string $text, Path ...$definitions): GateDefinitions
    {
        $running = GateDefinitions::none();

        foreach ($definitions as $definition) {
            $running = $running->with($definition, $text);
        }

        return $running;
    }
}
