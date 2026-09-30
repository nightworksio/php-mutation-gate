<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_put_contents;
use function getenv;
use function is_string;
use function is_subclass_of;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use Pest\Contracts\HasPrintableTestCaseName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function sprintf;
use function str_starts_with;
use function strval;

/**
 * The name of every test method of the test classes a run loaded, written to
 * the file `MUTATION_GATE_NAMES` names once the run ends: its coverage id's
 * `<class>::<method>`, its file and its description. A Pest test's
 * description is the one Pest gives it, its TestDox, and its file the one
 * Pest built its class from; a PHPUnit test's is its method's name, in the
 * file that declares its class.
 */
final readonly class Naming
{
    /** The prefix PHPUnit runs a public method as a test by, as a `#[Test]` does. */
    private const string PREFIX = 'test';

    /** The static property of a class Pest builds that holds the file it built it from. */
    private const string BUILT_FROM = '__filename';

    private function __construct(private string $file)
    {
    }

    /** Naming where the adapter named a file. */
    public static function fromEnvironment(): self|Off
    {
        return self::to(getenv(GateVariable::Names->value));
    }

    public static function to(string|false $file): self|Off
    {
        return is_string($file) && $file !== '' ? new self($file) : Off::NamingTests;
    }

    /**
     * Writes the name of every test of these classes that is a test case.
     *
     * @param list<string> $classes every class declared by the end of the run
     */
    public function write(array $classes): void
    {
        $tests = [];

        foreach ($classes as $class) {
            $tests = is_subclass_of($class, TestCase::class) ? [...$tests, ...$this->testsOf($class)] : $tests;
        }

        file_put_contents($this->file, JsonText::compact($tests));
    }

    /**
     * @param  class-string<TestCase>                                          $class
     * @return list<array{test: string, file: string, description: string}>
     */
    private function testsOf(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $pest = $reflection->implementsInterface(HasPrintableTestCaseName::class);
        $file = $this->fileOf($reflection, $pest);
        $tests = [];

        foreach ($reflection->isAbstract() ? [] : $reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $tests = $this->isTest($method) ? [...$tests, [
                'test' => sprintf('%s::%s', $class, $method->getName()),
                'file' => $file,
                'description' => $pest ? $this->describedBy($method) : $method->getName(),
            ]] : $tests;
        }

        return $tests;
    }

    /**
     * The file a test class's tests are in: the one Pest built its class
     * from, or the one that declares it.
     *
     * @param ReflectionClass<TestCase> $class
     */
    private function fileOf(ReflectionClass $class, bool $pest): string
    {
        $built = $pest ? $class->getStaticPropertyValue(self::BUILT_FROM, '') : '';

        return is_string($built) && $built !== '' ? $built : strval($class->getFileName());
    }

    /** Whether PHPUnit runs a public method as a test: it has `#[Test]`, as each of Pest's has, or its name says so. */
    private function isTest(ReflectionMethod $method): bool
    {
        return $method->getAttributes(Test::class) !== [] || str_starts_with($method->getName(), self::PREFIX);
    }

    /** The description Pest gives a test, in its TestDox; the method's name where it has none. */
    private function describedBy(ReflectionMethod $method): string
    {
        $description = $method->getName();

        foreach ($method->getAttributes(TestDox::class) as $testDox) {
            $description = $testDox->newInstance()->text();
        }

        return $description;
    }
}
