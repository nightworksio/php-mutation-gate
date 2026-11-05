<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * `timeouts.perTest` and `timeouts.testFloor` as the flows write them into a
 * built-in runner's options, and as the runner reads them back (ADR-0090):
 * the multiple, 0 where it is off, and the floor; the standard where they
 * write none.
 *
 * @internal
 */
final readonly class PerTestOptions
{
    /** The option that holds how many times its own time each test may run, 0 for no limit. */
    public const string MULTIPLE = 'testMultiple';

    /** The option that holds the least seconds each test may run. */
    public const string FLOOR = 'testFloorSeconds';

    /** The limit each test of a run is held to, as the options hold it; or why not. */
    public static function read(Options $options): PerTestLimit|Problem
    {
        $multiple = $options->integer(Key::of(self::MULTIPLE));
        $floor = $options->number(Key::of(self::FLOOR));
        $standard = PerTestLimit::standard();

        return match (true) {
            $multiple instanceof Problem => $multiple,
            $floor instanceof Problem => $floor,
            default => PerTestLimit::of(
                $multiple instanceof NotGiven ? $standard->multiple() : $multiple,
                $floor instanceof NotGiven ? $standard->floor() : Seconds::of($floor),
            ),
        };
    }

    /**
     * The options that hold this limit, as the flows write them.
     *
     * @return list<Member>
     */
    public static function of(PerTestLimit $limit): array
    {
        return [Member::of(self::MULTIPLE, $limit->multiple()), Member::of(self::FLOOR, $limit->floor()->seconds())];
    }
}
