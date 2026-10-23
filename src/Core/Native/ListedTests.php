<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function array_map;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

use function sprintf;

/**
 * The suite's tests and the groups each is in, as a framework's extension
 * lists them into a file, running none: JSON whose `listed` is each id in the
 * order the framework loads them, and whose `groups` is each group's ids by
 * its name.
 */
final readonly class ListedTests
{
    /** The member of the list that holds every test. */
    private const string TESTS = 'listed';

    /** The member of the list that holds each group's tests. */
    private const string GROUPS = 'groups';

    private const string UNREAD = <<<'SAID'
        %s did not list the tests of the suite: %s holds no list of them. It said:
        %s
        SAID;

    /** The list of these tests, as the extension writes it. */
    public static function encoded(Candidate ...$tests): string
    {
        $groups = [];

        foreach ($tests as $test) {
            foreach ($test->groups() as $group) {
                $groups[$group->name()][] = $test->key();
            }
        }

        return JsonText::compact([
            self::TESTS => array_map(static fn(Candidate $test): string => $test->key(), $tests),
            self::GROUPS => $groups,
        ]);
    }

    /** The listing a run of this program wrote to a file, as the file reads; or why there is none. */
    public static function read(Ran $ran, string|false $json, string $file, Program $program): TestListing|CannotJudge
    {
        $read = $ran->succeeded() && is_string($json) ? Node::decode($json) : NotGiven::value();

        if ($read instanceof NotGiven || ! Lenient::holdsMembers($read->field(self::TESTS))) {
            return CannotJudge::because(sprintf(self::UNREAD, $program->title(), $file, $ran->output()));
        }

        $listing = TestListing::of(self::idsIn($read->field(self::TESTS)));

        foreach (Lenient::entries($read->field(self::GROUPS)) as $name => $ids) {
            $listing = $listing->grouping(Group::named(sprintf('%s', $name)), self::idsIn($ids));
        }

        return $listing;
    }

    private static function idsIn(Node $place): TestIds
    {
        return TestIds::of(...array_map(
            static fn(Node $id): TestId => TestId::of(Lenient::text($id)),
            Lenient::items($place),
        ));
    }
}
