<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function is_array;
use function is_int;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LineRecording;
use NightWorksIO\MutationGate\Core\Runner\RunExtension;

/**
 * What a mutation run tells its split streams of Outbreak's extension,
 * `outbreak.so`, where a setting asks for it (ADR-0130, ADR-0147), and
 * whether its streams record lines with pcov instead.
 *
 * @internal
 */
final readonly class ToldExtension
{
    /**
     * What tells a split stream to load `outbreak.so`, and what it watches,
     * where a setting asks (ADR-0130, ADR-0147); nothing otherwise. It
     * records lines only for a quick round's kept map: a stand-in for the
     * suite's coverage report reads pcov's executable lines.
     *
     * @return array<string, string>
     */
    public static function of(RunExtension|NotGiven $extension, LineRecording $recording): array
    {
        $forMap = $recording->records() && ! $recording->standsIn();
        $streams = $extension instanceof RunExtension
            ? $extension->forStreams($forMap)
            : NotGiven::value();

        $floor = $extension instanceof RunExtension ? $extension->stepFloor() : NotGiven::value();

        return is_array($streams)
            ? [
                GateVariable::StreamExtension->value => $streams[0],
                GateVariable::Observe->value => $streams[1],
                ...is_int($floor) ? [GateVariable::StepFloor->value => (string) $floor] : [],
            ]
            : [];
    }

    /**
     * Whether the run's streams record lines with pcov: where they stand in
     * for the suite's coverage report, or record for the kept map without
     * `outbreak.so` recording them (ADR-0147).
     */
    public static function recordsWithPcov(RunExtension|NotGiven $extension, LineRecording $recording): bool
    {
        $byOutbreak = $extension instanceof RunExtension && $extension->recordsLines();

        return $recording->standsIn() || ($recording->records() && ! $byOutbreak);
    }
}
