<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use NightWorksIO\MutationGate\Core\Php\Nameless;

use function sprintf;

/**
 * The style a test asserts in: PHPUnit's `assert…` calls, Pest's
 * expectations chained after `expect()`, or PhpSpec's matchers on the object
 * a spec describes. Pest runs PHPUnit test classes too, so a style belongs to
 * an assertion, never to a runner (ADR-0025).
 * Its value is the word `stub --style` takes for it (ADR-0015).
 */
enum AssertionStyle: string
{
    case PhpUnit = 'phpunit';
    case Pest = 'pest';
    case PhpSpec = 'phpspec';

    /** What a spec throws to fail an example. */
    public const string FAILURE = '\\PhpSpec\\Exception\\Example\\FailureException';

    /** The result of the function around a survivor, as a suggestion writes it. */
    private const string CALLED = '%s(…)';

    /** What stands for a result where no function is around the survivor. */
    private const string RESULT = '…';

    /** PHPUnit's assertion of a value, on a subject, as a PHPUnit test writes it. */
    private const string PHPUNIT = '$this->assertSame(<expected>, %s)';

    /** Pest's expectation of a value, on a subject, as a Pest test writes it. */
    private const string PEST = 'expect(%s)->toBe(<expected>)';

    /** PhpSpec's match of a value, on a call to the object a spec describes, as a spec writes it. */
    private const string PHPSPEC = '$this->%s->shouldReturn(<expected>)';

    /**
     * The assertion of value, on the result of the function around the
     * survivors, that would kill what an assertion of existence or shape
     * let through.
     */
    public function suggestion(WeaklyAsserted $finding): string
    {
        $function = $finding->function();
        $subject = $function instanceof Nameless ? self::RESULT : sprintf(self::CALLED, $function);

        $written = match ($this) {
            self::PhpUnit => self::PHPUNIT,
            self::Pest => self::PEST,
            self::PhpSpec => self::PHPSPEC,
        };

        return sprintf($written, $subject);
    }
}
