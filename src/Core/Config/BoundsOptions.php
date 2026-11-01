<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * `timeouts.seconds`, `timeouts.most` and `timeouts.tighter` as the flows
 * write them into a built-in runner's options, and as the runner reads them
 * back: what each mutant's limit is kept between, the standard where they
 * write none.
 *
 * @internal
 */
final readonly class BoundsOptions
{
    /** The option that holds the least each mutant's run is allowed, `timeouts.seconds`. */
    public const string TIMEOUT = 'timeout';

    /** The option that holds the most each mutant's run is allowed, `timeouts.most`. */
    public const string MOST = 'most';

    /** What each mutant's limit is kept between, as the options hold it; or why not. */
    public static function read(Options $options): LimitBounds|Problem
    {
        $timeout = $options->number(Key::of(self::TIMEOUT));
        $most = $options->number(Key::of(self::MOST));
        $tighter = TighterOptions::read($options);
        $standard = Triage::standard();

        return match (true) {
            $timeout instanceof Problem => $timeout,
            $most instanceof Problem => $most,
            $tighter instanceof Problem => $tighter,
            default => LimitBounds::between(
                $timeout instanceof NotGiven ? $standard->limit() : Seconds::of($timeout),
                $most instanceof NotGiven ? $standard->most() : Seconds::of($most),
            )->tighterFor($tighter),
        };
    }

    /**
     * The options that hold a triage's bounds, as the flows write them.
     *
     * @return list<Member>
     */
    public static function of(Triage $triage): array
    {
        return [
            Member::of(self::TIMEOUT, $triage->limit()->seconds()),
            Member::of(self::MOST, $triage->most()->seconds()),
            TighterOptions::floor($triage->tighter()),
            TighterOptions::mutators($triage->tighter()),
        ];
    }
}
