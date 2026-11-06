<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\Alert\PostLimits;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;

/**
 * An alert a run leaves for `deliver` to post: the channel, a built-in alert reporter, the body it posts, and
 * how long it waits on the post.
 *
 * @internal
 */
final readonly class AlertPost
{
    private function __construct(private BuiltinReporter $channel, private string $body, private PostLimits $limits)
    {
    }

    /** An alert posted within the standard limits. */
    public static function of(BuiltinReporter $channel, string $body): self
    {
        return new self($channel, $body, PostLimits::standard());
    }

    /** This alert, posted within these limits. */
    public function within(PostLimits $limits): self
    {
        return new self($this->channel, $this->body, $limits);
    }

    public function limits(): PostLimits
    {
        return $this->limits;
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
