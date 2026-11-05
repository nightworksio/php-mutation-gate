<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * How a run writes its tests' starts and ends to the file whose growth is
 * its progress (ADR-0090): PHPUnit's events in text, as `--log-events-text`
 * writes them, the lines the gate's own PHPUnit extension writes, or the
 * beats a patched Pest's mutant process writes on its error output.
 */
enum TestEvents
{
    case PhpUnitText;
    case NativeResults;
    case Heartbeats;

    /** What a patched Pest's mutant process beats as its tests begin and as each finishes, a line of its own. */
    public const string BEAT = "\x06";

    /** What a patched Pest's mutant process writes before a test's id, rawurlencoded, as the test starts. */
    public const string TEST_STARTED = "\x02";
}
