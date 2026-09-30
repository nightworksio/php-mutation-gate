<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\Naming;

use Pest\Contracts\HasPrintableTestCaseName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A class as Pest builds one from a test file, never run: it names the file
 * it was built from, and describes each test in its TestDox.
 */
final class NamedPestTest extends TestCase implements HasPrintableTestCaseName
{
    /** The file Pest built the class from. */
    public static string $__filename = '/work/tests/MoneySpec.php';

    #[Test]
    #[TestDox('it adds')]
    public function __pest_evaluable_it_adds(): void
    {
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function __pest_evaluable_it_subtracts(): void
    {
        $this->addToAssertionCount(1);
    }

    public static function getPrintableTestCaseName(): string
    {
        return 'tests/MoneySpec.php';
    }

    public function getPrintableTestCaseMethodName(): string
    {
        return 'it adds';
    }

    public static function getLatestPrintableTestCaseMethodName(): string
    {
        return 'it adds';
    }
}
