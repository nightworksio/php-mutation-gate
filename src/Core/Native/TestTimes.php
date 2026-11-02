<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function explode;
use function is_numeric;
use function is_string;

use NightWorksIO\MutationGate\Core\Coverage\TimedTest;

use function rawurldecode;
use function rawurlencode;
use function sprintf;

/**
 * How long each test of a coverage run took, as the gate's recorder in the
 * framework writes it where the framework's own map times no test: a line
 * for each, its seconds, a space, and the test's id, encoded as an outcome's
 * is, so a data set's name that holds a space or a line break stays on its
 * line (see Outcome).
 */
final readonly class TestTimes
{
    /** The line that records how long a test took. */
    public static function line(string $test, float $seconds): string
    {
        return sprintf("%F%s%s\n", $seconds, Outcome::SEPARATOR, rawurlencode($test));
    }

    /**
     * Each test a file of times holds, with its time; none where there is no
     * file, and a line cut short or not of this form is passed over.
     *
     * @return list<TimedTest>
     */
    public static function read(string|false $text): array
    {
        $timed = [];

        foreach (is_string($text) ? explode("\n", $text) : [] as $line) {
            [$seconds, $test] = [...explode(Outcome::SEPARATOR, $line, 2), ''];
            $timed = is_numeric($seconds) && $test !== ''
                ? [...$timed, TimedTest::of(rawurldecode($test), (float) $seconds)]
                : $timed;
        }

        return $timed;
    }
}
