<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;

/**
 * What each unmutated control found, by the control (see Control); a
 * control the runner gave nothing for never ran.
 */
final readonly class ControlRuns
{
    /** Why a control the runner gave nothing for never ran. */
    public const string NOT_RUN = 'the runner ran no such control';

    /** @param array<string, ControlRun> $runs by each control's key */
    private function __construct(private array $runs)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, and what a control found, in place of what they held for it. */
    public function with(Control $control, ControlRun $run): self
    {
        return new self([$control->key() => $run] + $this->runs);
    }

    /** These, and those, those winning for a control both hold. */
    public function and(self $those): self
    {
        return new self($those->runs + $this->runs);
    }

    /** What a control found; never run, where these hold nothing for it. */
    public function of(Control $control): ControlRun
    {
        return array_key_exists($control->key(), $this->runs)
            ? $this->runs[$control->key()]
            : ControlRun::unrun(self::NOT_RUN);
    }
}
