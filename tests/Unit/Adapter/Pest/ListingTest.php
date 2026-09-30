<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Listing;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

it('reads the groups Pest lists, less PHPUnit\'s default and the group covers() adds', function (): void {
    $listing = implode("\n", [
        '',
        '   INFO  Available test groups:',
        '',
        ' - __pest_mutate_only (1 test)',
        ' - default (2 tests)',
        ' - holds:src/Held.php (1 test)',
        ' - my slow group (3 tests)',
        ' - mutation-canary (1 test).',
        '',
    ]);

    expect(Listing::groupsIn(Ran::finished(succeeded: true, output: $listing)))->toEqual(Groups::of(
        Group::named('holds:src/Held.php'),
        Group::named('my slow group'),
        Group::named('mutation-canary'),
    ));
});

it('cannot judge a listing without its heading, rather than finding no groups', function (): void {
    expect(Listing::groupsIn(Ran::finished(succeeded: true, output: ' - slow (1 test)')))->toEqual(CannotJudge::because(
        "Pest did not list the suite's groups, so no group can hold a path. Pest said:\n - slow (1 test)",
    ));
});

it('cannot judge a listing Pest failed to finish', function (): void {
    $ran = Ran::finished(succeeded: false, output: 'Available test groups:');

    expect(Listing::groupsIn($ran))->toEqual(CannotJudge::because(
        "Pest did not list the suite's groups, so no group can hold a path. Pest said:\nAvailable test groups:",
    ));
});
