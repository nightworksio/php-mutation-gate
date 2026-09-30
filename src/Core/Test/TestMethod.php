<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\File\Path;

use function preg_match;

use const PREG_UNMATCHED_AS_NULL;

use function sprintf;

/**
 * A coverage id as PHPUnit spells a test method's, and Pest's with it:
 * `<class>::<method>`, with `#<data set>` for a row of a data set. An id of
 * another shape is a class with no method.
 */
final readonly class TestMethod
{
    /** The class, the method and, where there is one, the data set's number or name. */
    private const string ID = '/^(?<class>.*?)::(?<method>[^#]*)(?:#(?:(?<number>\d+)|(?<name>.*)))?$/sD';

    /** @param string $row the data set as PHPUnit names its row, `#0` or `"one"`; empty for a whole test */
    private function __construct(private string $class, private string $method, private string $row)
    {
    }

    public static function of(TestId $test): self
    {
        if (preg_match(self::ID, $test->value(), $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            return new self($test->value(), '', '');
        }

        $row = match (true) {
            $parts['number'] !== null => sprintf('#%s', $parts['number']),
            $parts['name'] !== null => sprintf('"%s"', $parts['name']),
            default => '',
        };

        return new self($parts['class'], $parts['method'], $row);
    }

    public function className(): string
    {
        return $this->class;
    }

    /** The method's name; empty where the id names none. */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * The test in a file, as described there, or the row of it this id runs:
     * `with data set #0` or `with data set "one"`, as PHPUnit logs it.
     */
    public function in(Path $file, string $description): TestName|TestRow
    {
        $test = TestName::in($file, $description);

        return $this->row === '' ? $test : TestRow::of($test, $this->row);
    }
}
