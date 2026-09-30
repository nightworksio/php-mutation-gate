<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;

use DOMDocument;
use DOMElement;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;

use function preg_match;
use function sprintf;

/**
 * PHPUnit's JUnit log of a coverage run: how long each test took, and how long
 * each test class took, which is the time Infection sums for a mutant's
 * covering tests.
 */
final readonly class JUnit
{
    /** How PHPUnit logs the name of a data set's test. */
    private const string DATA_SET = '/^(?<method>\S+) with data set (?:#(?<number>\d+)|"(?<name>.*)")$/sD';

    private const string MISSING
        = '%s is not there or is not a JUnit log, so the gate cannot say how long the tests took.';

    /**
     * @param array<string, float> $classes each test class's seconds, by its name
     * @param array<string, float> $tests   each test's seconds, by its id
     */
    private function __construct(private array $classes, private array $tests)
    {
    }

    public static function at(DiskPath $file): self|CannotJudge
    {
        $document = XmlFile::read($file->value(), CannotJudge::because(sprintf(self::MISSING, $file->value())));

        if ($document instanceof CannotJudge) {
            return $document;
        }

        return new self(self::classesIn($document), self::testsIn($document));
    }

    /** How long a test class took, as the first suite with its name says; a class the log does not name took none. */
    public function classSeconds(string $class): float
    {
        return array_key_exists($class, $this->classes) ? $this->classes[$class] : 0.0;
    }

    /** @return array<string, float> each test's seconds, by the id coverage names it with */
    public function tests(): array
    {
        return $this->tests;
    }

    /** @return array<string, float> */
    private static function classesIn(DOMDocument $document): array
    {
        $classes = [];

        foreach ($document->getElementsByTagName('testsuite') as $suite) {
            $name = $suite->getAttribute('name');

            if (! array_key_exists($name, $classes)) {
                $classes[$name] = self::secondsOf($suite);
            }
        }

        return $classes;
    }

    /** @return array<string, float> */
    private static function testsIn(DOMDocument $document): array
    {
        $tests = [];

        foreach ($document->getElementsByTagName('testcase') as $case) {
            $test = sprintf('%s::%s', $case->getAttribute('class'), self::idOf($case->getAttribute('name')));
            $tests[$test] = self::secondsOf($case);
        }

        return $tests;
    }

    /**
     * A test's name as its coverage id spells it: PHPUnit logs a data set's
     * test as `<method> with data set #<n>` or `… "<name>"`, and covers it as
     * `<method>#<n>` or `<method>#<name>`.
     */
    private static function idOf(string $name): string
    {
        if (preg_match(self::DATA_SET, $name, $named) !== 1) {
            return $name;
        }

        return sprintf(
            '%s#%s%s',
            $named['method'],
            array_key_exists('number', $named) ? $named['number'] : '',
            array_key_exists('name', $named) ? $named['name'] : '',
        );
    }

    private static function secondsOf(DOMElement $element): float
    {
        return (float) $element->getAttribute('time');
    }
}
