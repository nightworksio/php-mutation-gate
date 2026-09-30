<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Format\Fit;

/**
 * How long a rejection's code and message may be where a record keeps them
 * (ADR-0020, decision 10). A record exists to explain a kill, and every
 * shard's results, every proof and the JSON report hold it, so a custom
 * rule's unbounded message must not grow them without bound. A cut ends in
 * `…`. The finding itself is compared whole (decision 9); only what is
 * recorded of it is cut.
 */
final readonly class RejectionLength
{
    /**
     * Characters of a code, four times the longest identifier PHPStan gave
     * in 12,878 findings at level max over symfony/console, nikic/php-parser
     * and sebastian/diff: 32.
     */
    private const int CODE = 128;

    /** Characters of a message, twice the longest of those findings: 497, and 180 at the 99th percentile. */
    private const int MESSAGE = 1024;

    private function __construct(private int $code, private int $message)
    {
    }

    /** 128 characters of a code, and 1,024 of a message. */
    public static function standard(): self
    {
        return new self(self::CODE, self::MESSAGE);
    }

    /** The finding as a record keeps it: its code and its message each cut to its length. */
    public function cut(Finding $finding): Finding
    {
        return Finding::error(Fit::line($finding->code(), $this->code), Fit::line($finding->message(), $this->message));
    }
}
