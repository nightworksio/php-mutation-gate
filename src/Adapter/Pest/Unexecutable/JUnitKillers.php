<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use DOMDocument;
use DOMElement;

use function in_array;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * The tests a trial's JUnit log says failed or errored, which killed its
 * mutant (ADR-0014, decision 17), each as the coverage map names it. Pest
 * logs a test of its own with `::` in its `file`, under its class without
 * Pest's `P\`, and by its description, of which Pest makes the method
 * `__pest_evaluable_<description>`: every `_` doubled, every space an `_`,
 * and every other byte that cannot be in a name an `_`. It logs a test
 * method by its name. A data set's test is logged with ` with data set
 * #<n>` or ` with data set "<name>"` after it, and named with `#<n>` or
 * `#<name>`. None where the log is not there or is not JUnit.
 */
final readonly class JUnitKillers
{
    /** How a data set's test is logged: its test, then its data set's number or name. */
    private const string DATA_SET = '/^(?<test>.*) with data set (?:#(?<number>\d+)|"(?<name>.*)")$/sD';

    /** The class Pest makes of a test file, by the class the log names. */
    private const string PEST_CLASS = 'P\%s';

    /** A byte Pest replaces in the method it makes of a description, as it cannot be in a name. */
    private const string UNNAMEABLE = '/[^a-zA-Z0-9_\x80-\xff]/';


    private const string IN_DATA_SET = '%s#%s';

    public static function in(string $log): TestIds
    {
        $document = new DOMDocument();

        if (! is_file($log) || ! $document->load($log, Xml::QUIET)) {
            return TestIds::none();
        }

        $killers = [];

        foreach ($document->getElementsByTagName('testcase') as $case) {
            $killers = [...$killers, ...self::failed($case) ? [self::idOf($case)] : []];
        }

        return TestIds::of(...$killers);
    }

    /** Whether the log says a test case failed an assertion, or errored. */
    private static function failed(DOMElement $case): bool
    {
        foreach ($case->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->tagName, FailedFirst::OUTCOMES, strict: true)) {
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
        )->value();

        return TestId::of($inSet ? sprintf(self::IN_DATA_SET, $id, self::setOf($parts)) : $id);
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
        $method = sprintf('%s%s', Selection::EVALUABLE, str_replace(' ', '_', str_replace('_', '__', $description)));

        return preg_replace(self::UNNAMEABLE, '_', $method) ?? $method;
    }
}
