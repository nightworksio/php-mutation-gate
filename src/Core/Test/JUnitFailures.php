<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use DOMDocument;
use DOMElement;
use NightWorksIO\MutationGate\Core\Format\Xml;

use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * The tests a JUnit log PHPUnit or Pest wrote says failed or errored, each as
 * the coverage map names it: what killed a trial's mutant (ADR-0014), or
 * what failed in an unmutated control (ADR-0008). Pest
 * logs a test of its own with `::` in its `file`, under its class without
 * Pest's `P\`, and by its description, of which Pest makes the method
 * `__pest_evaluable_<description>`: every `_` doubled, every space an `_`,
 * and every other byte that cannot be in a name an `_`. It logs a test
 * method by its name. A data set's test is logged with ` with data set
 * #<n>` or ` with data set "<name>"` after it, and named with `#<n>` or
 * `#<name>`. None where there is no log, or it is empty or not JUnit.
 *
 * @internal
 */
final readonly class JUnitFailures
{
    /** How a data set's test is logged: its test, then its data set's number or name. */
    private const string DATA_SET = '/^(?<test>.*) with data set (?:#(?<number>\d+)|"(?<name>.*)")$/sD';

    /** The class Pest makes of a test file, by the class the log names. */
    private const string PEST_CLASS = 'P\%s';

    /** A byte Pest replaces in the method it makes of a description, as it cannot be in a name. */
    private const string UNNAMEABLE = '/[^a-zA-Z0-9_\x80-\xff]/';

    /** The tests a log's text says failed or errored, in its order; none where it could not be read. */
    public static function of(string|false $log): TestIds
    {
        $document = new DOMDocument();

        if ($log !== false && $log !== '') {
            $document->loadXML($log, Xml::QUIET);
        }

        $failed = [];

        foreach ($document->getElementsByTagName('testcase') as $case) {
            $failed = [...$failed, ...self::failed($case) ? [self::idOf($case)] : []];
        }

        return TestIds::of(...$failed);
    }

    /** Whether the log says a test case failed an assertion, or errored. */
    private static function failed(DOMElement $case): bool
    {
        foreach ($case->childNodes as $child) {
            if ($child instanceof DOMElement && Defect::names($child->tagName)) {
                return true;
            }
        }

        return false;
    }

    /** A test case as the coverage map names its test. */
    private static function idOf(DOMElement $case): TestId
    {
        $pest = str_contains($case->getAttribute('file'), TestMethod::SEPARATOR);
        $class = $case->getAttribute('class');
        $name = $case->getAttribute('name');
        $inSet = preg_match(self::DATA_SET, $name, $parts, PREG_UNMATCHED_AS_NULL) === 1;
        $test = $inSet ? $parts['test'] : $name;
        $id = TestMethod::id(
            $pest ? sprintf(self::PEST_CLASS, $class) : $class,
            $pest ? self::evaluable($test) : $test,
        );

        return $inSet ? TestMethod::row($id, self::setOf($parts)) : $id;
    }

    /**
     * A data set's number, or its name where it has no number, as a logged
     * name's parts hold them.
     *
     * @param array{number: string|null, name: string|null} $parts
     */
    private static function setOf(array $parts): string
    {
        $number = $parts['number'];

        return $number ?? (string) $parts['name'];
    }

    /** The method Pest makes of a test's description. */
    private static function evaluable(string $description): string
    {
        $method = sprintf('%s%s', TestMethod::EVALUABLE, str_replace('_', '__', $description));

        return preg_replace(self::UNNAMEABLE, '_', $method) ?? $method;
    }
}
