<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * `timeouts.tighter` as the flows write it into a built-in runner's options,
 * and as the runner reads it back: the standard where they write none.
 */
final readonly class TighterOptions
{
    /** The option that holds the floor of the listed mutators' silence limit, in seconds. */
    public const string FLOOR = 'tighterFloor';

    /** The option that holds the listed mutators, by their short names. */
    public const string MUTATORS = 'tighterMutators';

    /** The mutators whose silence limit has a lower floor, as the options hold them; or why not. */
    public static function read(Options $options): TighterSilence|Problem
    {
        $floor = $options->number(Key::of(self::FLOOR));
        $mutators = $options->texts(Key::of(self::MUTATORS));
        $standard = TighterSilence::standard();

        return match (true) {
            $floor instanceof Problem => $floor,
            $mutators instanceof Problem => $mutators,
            default => TighterSilence::of(
                $floor instanceof NotGiven ? $standard->floor() : Seconds::of($floor),
                ...$mutators instanceof NotGiven ? [...$standard] : [...$mutators],
            ),
        };
    }

    /** The option that holds the floor, as the flows write it. */
    public static function floor(TighterSilence $tighter): Member
    {
        return Member::of(self::FLOOR, $tighter->floor()->seconds());
    }

    /** The option that holds the mutators, as the flows write it. */
    public static function mutators(TighterSilence $tighter): Member
    {
        return Member::of(self::MUTATORS, Json::items(...$tighter));
    }
}
