<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;

use DOMDocument;
use DOMElement;

use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * PHPUnit's JUnit log of a coverage run: how long each test took, and how long
 * each test class took, which is the time Infection sums for a mutant's
 * covering tests.
 */
final readonly class JUnit
{
    /** Options that keep the reader off the network and out of the error log. */
    private const int QUIET = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;

    private const string MISSING
        = '%s is not there or is not a JUnit log, so the gate cannot say how long the tests took.';

    /**
     * @param array<string, float> $classes each test class's seconds, by its name
     * @param array<string, float> $tests   each test's seconds, by its id
     */
    private function __construct(private array $classes, private array $tests)
    {
    }

    public static function at(string $file): self|CannotJudge
    {
        $document = new DOMDocument();

        if (! is_file($file) || ! $document->load($file, self::QUIET)) {
            return CannotJudge::because(sprintf(self::MISSING, $file));
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
            $test = sprintf('%s::%s', $case->getAttribute('class'), $case->getAttribute('name'));
            $tests[$test] = self::secondsOf($case);
        }

        return $tests;
    }

    private static function secondsOf(DOMElement $element): float
    {
        return (float) $element->getAttribute('time');
    }
}
