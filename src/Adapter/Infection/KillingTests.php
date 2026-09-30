<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function is_array;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function preg_match;
use function preg_split;
use function sprintf;

/**
 * The tests that killed a mutant, read from what PHPUnit printed for its run,
 * which Infection's JSON log keeps as `processOutput`. PHPUnit lists the tests
 * that failed or met an error under `There was 1 failure:` or `There were 2
 * errors:`, each as `<n>) <class>::<method>`, and a data set's test as
 * `<class>::<method>#<number> with data (…)` or `…@<name> with data (…)`.
 * Each is named as the coverage map names it: `<class>::<method>`, with
 * `#<data set>` for a data set. A run killed with no such list, by a crash
 * or a timeout, names none.
 */
final readonly class KillingTests
{
    /** A list PHPUnit prints after a run. */
    private const string LIST = '/^There (?:was|were) \d+ /';

    /** A list of the tests that failed, or that met an error. */
    private const string DEFECTS = '/^There (?:was|were) \d+ (?:failure|error)s?:$/D';

    /** One test in such a list, and the data set it ran with where it ran with one. */
    private const string ENTRY = '/^\d+\) (?<test>[^\s#@]+::[^\s#@]+)(?:(?<set>[#@].*?) with data \(.*\))?$/D';

    /** The lines of what PHPUnit printed. */
    private const string LINES = '/\R/';

    public static function in(string $output): TestIds
    {
        $lines = preg_split(self::LINES, $output);
        $tests = TestIds::none();
        $listing = false;

        foreach (is_array($lines) ? $lines : [] as $line) {
            $listing = preg_match(self::LIST, $line) === 1 ? preg_match(self::DEFECTS, $line) === 1 : $listing;
            $tests = $listing ? self::withEntry($tests, $line) : $tests;
        }

        return $tests;
    }

    /** These tests, and the one a line of a list names, as the coverage map names it. */
    private static function withEntry(TestIds $tests, string $line): TestIds
    {
        if (preg_match(self::ENTRY, $line, $entry) !== 1) {
            return $tests;
        }

        $set = array_key_exists('set', $entry) ? sprintf('#%s', mb_substr($entry['set'], 1)) : '';

        return $tests->with(TestId::of(sprintf('%s%s', $entry['test'], $set)));
    }
}
