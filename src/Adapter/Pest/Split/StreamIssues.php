<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;
use PHPUnit\Event\Test\DeprecationTriggered;
use PHPUnit\Event\Test\DeprecationTriggeredSubscriber;
use PHPUnit\Event\Test\ErrorTriggered;
use PHPUnit\Event\Test\ErrorTriggeredSubscriber;
use PHPUnit\Event\Test\NoticeTriggered;
use PHPUnit\Event\Test\NoticeTriggeredSubscriber;
use PHPUnit\Event\Test\PhpDeprecationTriggered;
use PHPUnit\Event\Test\PhpDeprecationTriggeredSubscriber;
use PHPUnit\Event\Test\PhpNoticeTriggered;
use PHPUnit\Event\Test\PhpNoticeTriggeredSubscriber;
use PHPUnit\Event\Test\PhpunitDeprecationTriggered;
use PHPUnit\Event\Test\PhpunitDeprecationTriggeredSubscriber;
use PHPUnit\Event\Test\PhpunitErrorTriggered;
use PHPUnit\Event\Test\PhpunitErrorTriggeredSubscriber;
use PHPUnit\Event\Test\PhpunitNoticeTriggered;
use PHPUnit\Event\Test\PhpunitNoticeTriggeredSubscriber;
use PHPUnit\Event\Test\PhpunitWarningTriggered;
use PHPUnit\Event\Test\PhpunitWarningTriggeredSubscriber;
use PHPUnit\Event\Test\PhpWarningTriggered;
use PHPUnit\Event\Test\PhpWarningTriggeredSubscriber;
use PHPUnit\Event\Test\WarningTriggered;
use PHPUnit\Event\Test\WarningTriggeredSubscriber;

/**
 * The issues a test can raise in a stream's parent, each of which marks the
 * test as troubled (ADR-0135): risky, or a deprecation, an error, a notice
 * or a warning, triggered by PHP, by the code or by PHPUnit. A run planned
 * without a fresh map records it beside the test's lines, and a suite with a
 * troubled test is never taken from the streams.
 *
 * @internal
 */
final readonly class StreamIssues
{
    /** @return list<Subscriber> */
    public static function subscribers(): array
    {
        return [
            new class implements ConsideredRiskySubscriber {
                public function notify(ConsideredRisky $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements DeprecationTriggeredSubscriber {
                public function notify(DeprecationTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements ErrorTriggeredSubscriber {
                public function notify(ErrorTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements NoticeTriggeredSubscriber {
                public function notify(NoticeTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements PhpDeprecationTriggeredSubscriber {
                public function notify(PhpDeprecationTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements PhpNoticeTriggeredSubscriber {
                public function notify(PhpNoticeTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements PhpWarningTriggeredSubscriber {
                public function notify(PhpWarningTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements PhpunitDeprecationTriggeredSubscriber {
                public function notify(PhpunitDeprecationTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements PhpunitErrorTriggeredSubscriber {
                public function notify(PhpunitErrorTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements PhpunitNoticeTriggeredSubscriber {
                public function notify(PhpunitNoticeTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements PhpunitWarningTriggeredSubscriber {
                public function notify(PhpunitWarningTriggered $event): void
                {
                    Stream::troubled();
                }
            },
            new class implements WarningTriggeredSubscriber {
                public function notify(WarningTriggered $event): void
                {
                    Stream::troubled();
                }
            },
        ];
    }
}
