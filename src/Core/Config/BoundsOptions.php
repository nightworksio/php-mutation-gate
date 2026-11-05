<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * `timeouts.seconds`, `timeouts.most`, `timeouts.multiple` and `timeouts.tighter` as the flows
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

    /** The option that holds how many times its covering tests' own time a mutant is allowed, `timeouts.multiple`. */
    public const string MULTIPLE = 'multiple';

    /** The option that says the floor is learned from the measured start-up, `timeouts.seconds: "learned"`. */
    public const string LEARNS_FLOOR = 'learnsFloor';

    /** The option that says the multiple is learned from the ledger's kills, `timeouts.multiple: "learned"`. */
    public const string LEARNS_MULTIPLE = 'learnsMultiple';

    /** What each mutant's limit is kept between, as the options hold it; or why not. */
    public static function read(Options $options): LimitBounds|Problem
    {
        $timeout = $options->number(Key::of(self::TIMEOUT));
        $most = $options->number(Key::of(self::MOST));
        $multiple = $options->number(Key::of(self::MULTIPLE));
        $learnsFloor = $options->flag(Key::of(self::LEARNS_FLOOR));
        $learnsMultiple = $options->flag(Key::of(self::LEARNS_MULTIPLE));
        $tighter = TighterOptions::read($options);
        $standard = Triage::standard();

        return match (true) {
            $timeout instanceof Problem => $timeout,
            $most instanceof Problem => $most,
            $multiple instanceof Problem => $multiple,
            $learnsFloor instanceof Problem => $learnsFloor,
            $learnsMultiple instanceof Problem => $learnsMultiple,
            $tighter instanceof Problem => $tighter,
            default => self::learning(
                LimitBounds::between(
                    $timeout instanceof NotGiven ? $standard->limit() : Seconds::of($timeout),
                    $most instanceof NotGiven ? $standard->most() : Seconds::of($most),
                )->tighterFor($tighter)->multipliedBy(match (true) {
                    $learnsMultiple === true => Learned::Learned,
                    $multiple instanceof NotGiven => Multiple::standard(),
                    default => Multiple::of($multiple),
                }),
                $learnsFloor,
            ),
        };
    }

    /**
     * The options that hold a triage's bounds, as the flows write them.
     *
     * @return list<Member>
     */
    public static function of(Triage $triage): array
    {
        $bounds = $triage->bounds();

        return [
            Member::of(self::TIMEOUT, $triage->limit()->seconds()),
            Member::of(self::MOST, $triage->most()->seconds()),
            $bounds->learnsMultiple()
                ? Member::of(self::LEARNS_MULTIPLE, value: true)
                : Member::of(self::MULTIPLE, $bounds->multiple()->times()),
            ...$bounds->learnsFloor() ? [Member::of(self::LEARNS_FLOOR, value: true)] : [],
            TighterOptions::floor($triage->tighter()),
            TighterOptions::mutators($triage->tighter()),
        ];
    }

    /** These bounds, their floor learned where the options say it is. */
    private static function learning(LimitBounds $bounds, bool|NotGiven $learnsFloor): LimitBounds
    {
        return $learnsFloor === true ? $bounds->learningFloor() : $bounds;
    }
}
