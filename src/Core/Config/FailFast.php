<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * `run.failFast`: what a pull request's shard does once a confirmed survivor leaves its run no way to pass
 * (ADR-0008, ADR-0103).
 */
enum FailFast: string
{
    /** Nothing: the shard passes, and the verdict alone fails. */
    case Off = 'off';

    /** The shard judges every unit, so the verdict names every survivor, and then fails. */
    case Mark = 'mark';

    /** The shard stops there, its other units unjudged, and fails. */
    case Stop = 'stop';

    /** The shard stops there and fails, and the CI cancels the run's other shards where it can. */
    case Cancel = 'cancel';
    private const string STOPS_ONLY = <<<'SAID'
        run.failFast is cancel, but only GitHub Actions' matrix in the reusable workflow cancels the other shards.
        On this CI a doomed shard stops, as under stop, and the others run on.
        SAID;

    /** Whether a doomed shard fails. */
    public function marks(): bool
    {
        return $this !== self::Off;
    }

    /** Whether a doomed shard stops before its other units. */
    public function stops(): bool
    {
        return $this === self::Stop || $this === self::Cancel;
    }

    /**
     * Why a pull request's run off GitHub Actions stops a doomed shard without cancelling the others, where it is
     * `cancel`: only the reusable workflow's matrix cancels them; nothing on any other run.
     */
    public function warnings(bool $proposed, bool $onGitHub): Warnings
    {
        return $this === self::Cancel && $proposed && ! $onGitHub
            ? Warnings::of(Warning::that(self::STOPS_ONLY))
            : Warnings::none();
    }

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Off => 'Reach::failFastOff()',
            self::Mark => 'Reach::failFastMarking()',
            self::Stop => 'Reach::failFastStopping()',
            self::Cancel => 'Reach::failFastCancelling()',
        };
    }
}
