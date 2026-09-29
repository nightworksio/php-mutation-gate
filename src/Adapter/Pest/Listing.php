<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_flip;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

use function preg_match_all;
use function sprintf;
use function str_contains;

/** The groups `pest --list-groups` prints, less the two Pest and PHPUnit add on their own. */
final readonly class Listing
{
    private const string HEADING = 'Available test group';

    /** ` - <name> (<n> test|tests)`, and the last entry ends in a full stop. */
    private const string ENTRY = '/^\s*-\s+(?<name>.+?)\s+\(\d+ tests?\)\.?\s*$/mu';

    /** PHPUnit's group for tests in none, and the group `covers()` and `mutates()` put files in. */
    private const array NOT_THE_SUITES = ['default', '__pest_mutate_only'];

    public static function groupsIn(Ran $ran): Groups|CannotJudge
    {
        if (! $ran->succeeded() || ! str_contains($ran->output(), self::HEADING)) {
            return CannotJudge::because(sprintf(
                "Pest did not list the suite's groups, so no group can hold a path. Pest said:\n%s",
                $ran->output(),
            ));
        }

        preg_match_all(self::ENTRY, $ran->output(), $entries);
        $groups = Groups::none();

        foreach ($entries['name'] as $name) {
            if (! array_key_exists($name, array_flip(self::NOT_THE_SUITES))) {
                $groups = $groups->with(Group::named($name));
            }
        }

        return $groups;
    }
}
