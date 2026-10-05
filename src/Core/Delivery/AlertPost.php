<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;

/** An alert a run leaves for `deliver` to post: the channel, a built-in alert reporter, and the body it posts. */
final readonly class AlertPost
{
    private function __construct(private BuiltinReporter $channel, private string $body)
    {
    }

    public static function of(BuiltinReporter $channel, string $body): self
    {
        return new self($channel, $body);
    }

    public function channel(): BuiltinReporter
    {
        return $this->channel;
    }

    public function body(): string
    {
        return $this->body;
    }
}
