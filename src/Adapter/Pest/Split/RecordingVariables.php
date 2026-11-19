<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Runner\LineRecording;

/**
 * What a run whose streams record tells Pest's own process: the directory
 * they record each test's lines in, as a stand-in for the suite's coverage
 * report or for the kept map (ADR-0135, ADR-0147).
 *
 * @internal
 */
final readonly class RecordingVariables
{
    /** @return array<string, string> the variables, none where the streams record nothing */
    public static function of(LineRecording $recording): array
    {
        return match (true) {
            $recording->standsIn() => [GateVariable::SplitRecording->value => $recording->directory()],
            $recording->records() => [GateVariable::SplitLines->value => $recording->directory()],
            default => [],
        };
    }
}
