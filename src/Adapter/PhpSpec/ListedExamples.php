<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_map;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

use function sprintf;

/** The examples of the suite and their holding groups, as a listing run of the launcher wrote them (see Listing). */
final readonly class ListedExamples
{
    private const string UNREAD = <<<'SAID'
        PhpSpec did not list the examples of the suite: %s holds no list of them. It said:
        %s
        SAID;

    /** The listing a run wrote to a file, as the file reads; or why there is none. */
    public static function in(Ran $ran, string|false $json, string $file): TestListing|CannotJudge
    {
        $read = $ran->succeeded() && is_string($json) ? Node::decode($json) : NotGiven::value();

        if ($read instanceof NotGiven || ! Lenient::holdsMembers($read->field(Listing::EXAMPLES))) {
            return CannotJudge::because(sprintf(self::UNREAD, $file, $ran->output()));
        }

        $listing = TestListing::of(self::idsIn($read->field(Listing::EXAMPLES)));

        foreach (Lenient::entries($read->field(Listing::GROUPS)) as $name => $ids) {
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
