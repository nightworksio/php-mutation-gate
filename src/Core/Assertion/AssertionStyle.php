<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use NightWorksIO\MutationGate\Core\Php\Nameless;

use function sprintf;

/**
 * The style a test asserts in: PHPUnit's `assert…` calls, or Pest's
 * expectations chained after `expect()`. Pest runs PHPUnit test classes too,
 * so a style belongs to an assertion, never to a runner (ADR-0025, decision 6).
 * Its value is the word `stub --style` takes for it (ADR-0015, decision 2).
 */
enum AssertionStyle: string
{
    case PhpUnit = 'phpunit';
    case Pest = 'pest';

    /** The result of the function around a survivor, as a suggestion writes it. */
    private const string CALLED = '%s(…)';

    /** What stands for a result where no function is around the survivor. */
    private const string RESULT = '…';

    /** PHPUnit's assertion of a value, on a subject, as a PHPUnit test writes it. */
    private const string PHPUNIT = '$this->assertSame(<expected>, %s)';

    /** Pest's expectation of a value, on a subject, as a Pest test writes it. */
    private const string PEST = 'expect(%s)->toBe(<expected>)';

    /**
     * The assertion of value, on the result of the function around the
     * survivors, that would kill what an assertion of existence or shape
     * let through.
     */
    public function suggestion(WeaklyAsserted $finding): string
    {
        $function = $finding->function();
        $subject = $function instanceof Nameless ? self::RESULT : sprintf(self::CALLED, $function);

        return sprintf($this === self::PhpUnit ? self::PHPUNIT : self::PEST, $subject);
    }
}
