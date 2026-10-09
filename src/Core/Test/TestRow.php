<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function sprintf;

/**
 * One row of a test's data set, which folds into its test: the test is
 * useless only when every row is (ADR-0014, decision 6).
 */
final readonly class TestRow
{
    /** A test's name with its row's, as PHPUnit and Pest name a row: `<test> with data set <row>`. */
    public const string NAMED = '%s with data set %s';

    private function __construct(private TestName $test, private string $row)
    {
    }

    /** The row of this test, named as the runner names it, such as `#0` or `"one"`. */
    public static function of(TestName $test, string $row): self
    {
        return new self($test, $row);
    }

    /** The test this row folds into. */
    public function test(): TestName
    {
        return $this->test;
    }

    /** The row's name, as the runner spells it. */
    public function row(): string
    {
        return $this->row;
    }

    /** The test's description with the row's, as `it adds with data set "one"`. */
    public function description(): string
    {
        return sprintf(self::NAMED, $this->test->description(), $this->row);
    }

    /** `tests/Unit/MoneyTest.php::it adds with data set "one"`. */
    public function value(): string
    {
        return sprintf('%s::%s', $this->test->file()->value(), $this->description());
    }
}
