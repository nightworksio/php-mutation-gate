<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Infection\AbstractTestFramework\Coverage\TestLocation;
use Infection\Mutant\Mutant;
use Infection\Mutation\Mutation;
use Infection\Mutator\Arithmetic\Plus;
use Infection\PhpParser\MutatedNode;
use Later\Interfaces\Deferred;

use function Later\now;

use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Scalar\Int_;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/** An Infection mutant of `$a + $b` on a line of a file, covered by these tests, as Infection builds one. */
final readonly class InfectionMutant
{
    /** @param list<TestLocation> $tests */
    public static function of(array $tests, string $file = '/p/src/Money.php', int $line = 11, string $diff = "--- a\n+++ b"): Mutant
    {
        $mutation = new Mutation(
            $file,
            [],
            Plus::class,
            'Plus',
            [
                'startLine' => $line,
                'endLine' => $line,
                'startTokenPos' => 0,
                'endTokenPos' => 0,
                'startFilePos' => 0,
                'endFilePos' => 0,
            ],
            Minus::class,
            MutatedNode::wrap(new Minus(new Int_(1), new Int_(2))),
            0,
            $tests,
            [],
            '',
        );

        // Infection takes the pretty printed original deferred before 0.35.3, and as text from then on.
        $type = new ReflectionParameter([Mutant::class, '__construct'], 'prettyPrintedOriginalCode')->getType();
        $deferred = $type instanceof ReflectionNamedType && $type->getName() === Deferred::class;

        return new ReflectionClass(Mutant::class)
            ->newInstance('/m/1.php', $mutation, now('<?php'), now($diff), $deferred ? now('') : '');
    }

    /** A test as Infection locates it, timed by its whole class. */
    public static function test(string $method, float $seconds): TestLocation
    {
        return new TestLocation($method, '/p/tests/MoneyTest.php', $seconds);
    }

    /** A test as Infection locates it where it timed none. */
    public static function untimed(string $method): TestLocation
    {
        return TestLocation::forTestMethod($method);
    }
}
