<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('holds a test\'s id, as a coverage map keys it too, its file and its groups', function (): void {
    $candidate = Candidate::of('Tests\MoneyTest::adds', '/p/tests/MoneyTest.php', Group::holding('src/Money.php'), Group::named('slow'));

    expect($candidate->id())->toEqual(TestId::of('Tests\MoneyTest::adds'))
        ->and($candidate->key())->toBe('Tests\MoneyTest::adds')
        ->and($candidate->file())->toBe('/p/tests/MoneyTest.php')
        ->and($candidate->groups())->toEqual([Group::holding('src/Money.php'), Group::named('slow')])
        ->and($candidate->isIn(Group::named('holds:src/Money.php')))->toBeTrue()
        ->and($candidate->isIn(Group::named('slow')))->toBeTrue()
        ->and($candidate->isIn(Group::named('fast')))->toBeFalse()
        ->and(Candidate::of('T::a', '/t.php')->groups())->toBe([]);
});
