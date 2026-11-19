<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamIssues;
use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\DeprecationTriggered;
use PHPUnit\Event\Test\ErrorTriggered;
use PHPUnit\Event\Test\NoticeTriggered;
use PHPUnit\Event\Test\PhpDeprecationTriggered;
use PHPUnit\Event\Test\PhpNoticeTriggered;
use PHPUnit\Event\Test\PhpunitDeprecationTriggered;
use PHPUnit\Event\Test\PhpunitErrorTriggered;
use PHPUnit\Event\Test\PhpunitNoticeTriggered;
use PHPUnit\Event\Test\PhpunitWarningTriggered;
use PHPUnit\Event\Test\PhpWarningTriggered;
use PHPUnit\Event\Test\WarningTriggered;

/**
 * The event a subscriber hears, by its notify method's parameter.
 *
 * @return class-string
 */
function streamIssueHeard(Subscriber $subscriber): string
{
    $type = new ReflectionMethod($subscriber, 'notify')->getParameters()[0]->getType();
    $heard = $type instanceof ReflectionNamedType ? $type->getName() : '';

    return class_exists($heard) ? $heard : Subscriber::class;
}

it('troubles a test on every issue it can raise: risky, or a deprecation, an error, a notice or a warning from PHP, the code or PHPUnit', function (): void {
    $heard = [];

    foreach (StreamIssues::subscribers() as $subscriber) {
        $event = streamIssueHeard($subscriber);
        $heard[] = $event;
        new ReflectionMethod($subscriber, 'notify')->invoke($subscriber, new ReflectionClass($event)->newInstanceWithoutConstructor());
    }

    expect($heard)->toEqualCanonicalizing([
        ConsideredRisky::class,
        DeprecationTriggered::class,
        ErrorTriggered::class,
        NoticeTriggered::class,
        PhpDeprecationTriggered::class,
        PhpNoticeTriggered::class,
        PhpWarningTriggered::class,
        PhpunitDeprecationTriggered::class,
        PhpunitErrorTriggered::class,
        PhpunitNoticeTriggered::class,
        PhpunitWarningTriggered::class,
        WarningTriggered::class,
    ]);
});
