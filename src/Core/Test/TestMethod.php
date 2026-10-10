<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function in_array;
use function mb_strpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Path;

use function preg_match;

use const PREG_UNMATCHED_AS_NULL;

use function sprintf;

/**
 * A coverage id as PHPUnit spells a test method's, and Pest's with it:
 * `<class>::<method>`, with `#<data set>` for a row of a data set.
 */
final readonly class TestMethod
{
    /** What separates a test method from its class. */
    public const string SEPARATOR = '::';
    /** The class, the method and, where there is one, the data set's number or name. */
    private const string ID = '/^(?<class>.*?)::(?<method>[^#]+)(?:#(?:(?<number>\d+)|(?<name>.*)))?$/sD';

    /**
     * @param non-empty-string $method
     * @param string           $row    the data set as PHPUnit names its row, `#0` or `"one"`; empty for a whole test
     */
    private function __construct(private string $class, private string $method, private string $row)
    {
    }

    /** A test method's id: `<class>::<method>`. */
    public static function id(string $class, string $method): TestId
    {
        return TestId::of(sprintf('%s%s%s', $class, self::SEPARATOR, $method));
    }

    /** The test method an id runs; the id itself where it names none, such as a `.phpt` file's. */
    public static function of(TestId $test): self|TestId
    {
        if (preg_match(self::ID, $test->value(), $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            return $test;
        }

        $row = match (true) {
            $parts['number'] !== null => sprintf('#%s', $parts['number']),
            $parts['name'] !== null => sprintf('"%s"', $parts['name']),
            default => '',
        };

        return new self($parts['class'], $parts['method'], $row);
    }

    /**
     * The class an id's test is in: a test method's class, or the whole id
     * where it names no method. It reads the id as `of` does, without the
     * pattern: the class ends at the first `::` that a method follows.
     */
    public static function classOf(TestId $test): string
    {
        $id = $test->value();
        $at = mb_strpos($id, self::SEPARATOR);

        while ($at !== false && in_array(mb_substr($id, $at + 2, 1), ['', '#'], strict: true)) {
            $at = mb_strpos($id, self::SEPARATOR, $at + 1);
        }

        return $at === false ? $id : mb_substr($id, 0, $at);
    }

    public function className(): string
    {
        return $this->class;
    }

    /** @return non-empty-string */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * The test as PHPUnit names it where a `--filter` reads it:
     * `<class>::<method>`, and a row's as `… with data set #0` or
     * `… with data set "one"`.
     */
    public function named(): string
    {
        $test = self::id($this->class, $this->method)->value();

        return $this->row === '' ? $test : sprintf(TestRow::NAMED, $test, $this->row);
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
