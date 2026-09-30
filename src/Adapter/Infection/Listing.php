<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

use function preg_match;
use function preg_match_all;
use function sprintf;

/** The groups `phpunit --list-groups` prints, each on a line of its own under its heading. */
final readonly class Listing
{
    private const string HEADING = '/^Available test groups?:$/m';

    /** A group's line, with the count of its tests that PHPUnit adds. */
    private const string GROUP = '/^ - (?<name>.+?)(?: \(\d+ tests?\))?$/m';

    private const string UNLISTED = "PHPUnit did not list the suite's groups. PHPUnit said:\n%s";

    public static function groupsIn(Ran $ran): Groups|CannotJudge
    {
        if (! $ran->succeeded() || preg_match(self::HEADING, $ran->output(), $heading, PREG_OFFSET_CAPTURE) !== 1) {
            return CannotJudge::because(sprintf(self::UNLISTED, $ran->output()));
        }

        preg_match_all(self::GROUP, mb_substr($ran->output(), $heading[0][1]), $lines);
        $groups = Groups::none();

        foreach ($lines['name'] as $name) {
            $groups = $groups->with(Group::named($name));
        }

        return $groups;
    }
}
