<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function get_declared_classes;
use function is_subclass_of;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Core\NotGiven;
use Pest\Contracts\HasPrintableTestCaseName;
use Pest\Support\Str;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_replace;
use function sprintf;

/**
 * The test a mutant's own run names as its killer where collecting its tests
 * failed before any ran (ADR-0014, decision 17): Pest's `DatasetMissing`
 * names the test, by its description and its file, whose dataset gave no
 * case. Its id is the class Pest made of that file in this process, which
 * collected the suite unmutated, and its method as Pest names a test's; none
 * where what the run printed names no such test, or no class of that file is
 * loaded here.
 */
final readonly class CollectionKiller
{
    /** What Pest prints where a test's dataset gave no case: the test's description, then its file. */
    private const string MISSING = '/The test \[(?<test>.+?)\] in \[(?<file>[^\]]+)\] expects \[\d+\] argument/s';

    /** The colours and styles a terminal reads, which Pest prints into what it says. */
    private const string STYLE = '/\e\[[0-9;]*m/';

    /** The test what an own run printed names, by its id; or none. */
    public static function in(string $printed): string|NotGiven
    {
        $plain = (string) preg_replace(self::STYLE, '', $printed);

        if (preg_match(self::MISSING, $plain, $found) !== 1) {
            return NotGiven::value();
        }

        $class = self::classOf($found['file']);

        return $class instanceof NotGiven ? $class : sprintf('%s::%s', $class, Str::evaluable($found['test']));
    }

    /** The class Pest made of a test file in this process, by the file's path; or none. */
    private static function classOf(string $file): string|NotGiven
    {
        foreach (get_declared_classes() as $class) {
            $made = is_subclass_of($class, TestCase::class)
                && is_subclass_of($class, HasPrintableTestCaseName::class)
                && Naming::fileOfClass($class) === $file;

            if ($made) {
                return $class;
            }
        }

        return NotGiven::value();
    }
}
