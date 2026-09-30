<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Listing;
use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

it('reads each group PHPUnit lists, without the count of its tests', function (): void {
    $output = "PHPUnit 13.3.6 by Sebastian Bergmann and contributors.\n\nAvailable test groups:\n"
        . " - default (3 tests)\n - holds:src/Held.php (1 test)\n - slow\n";

    expect(Listing::groupsIn(Ran::finished(succeeded: true, output: $output)))
        ->toEqual(Groups::of(Group::named('default'), Group::named('holds:src/Held.php'), Group::named('slow')));
});

it('reads only the lines under the heading', function (): void {
    $output = " - before\nAvailable test group:\n - after (2 tests)\n";

    expect(Listing::groupsIn(Ran::finished(succeeded: true, output: $output)))->toEqual(Groups::of(Group::named('after')));
});

it('cannot judge a listing without its heading, or a PHPUnit that failed', function (): void {
    $failed = "Available test groups:\n - default\n";

    expect(Listing::groupsIn(Ran::finished(succeeded: true, output: 'No groups here')))
        ->toEqual(CannotJudge::because("PHPUnit did not list the suite's groups. PHPUnit said:\nNo groups here"))
        ->and(Listing::groupsIn(Ran::finished(succeeded: false, output: $failed)))
        ->toEqual(CannotJudge::because(sprintf("PHPUnit did not list the suite's groups. PHPUnit said:\n%s", $failed)));
});
