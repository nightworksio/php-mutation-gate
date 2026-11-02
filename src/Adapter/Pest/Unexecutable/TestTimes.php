<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use DOMDocument;

use function is_file;
use function is_numeric;

use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * How long the tests a JUnit log names took on their own, one after another:
 * the sum of each test case's `time`, which leaves out the run's start-up.
 *
 * @internal
 */
final readonly class TestTimes
{
    /** The attribute a test case's seconds are in. */
    private const string TIME = 'time';

    /** The tests' own time; unmeasured where the log is not there or not JUnit, names no test, or times one not. */
    public static function in(string $log): Seconds|Unmeasured
    {
        $document = new DOMDocument();

        if (is_file($log)) {
            $document->load($log, Xml::QUIET);
        }

        $total = 0.0;
        $cases = $document->getElementsByTagName('testcase');

        foreach ($cases as $case) {
            $time = $case->getAttribute(self::TIME);

            if (! is_numeric($time)) {
                return Unmeasured::duration();
            }

            $total += $time;
        }

        return $cases->length > 0 ? Seconds::of($total) : Unmeasured::duration();
    }
}
