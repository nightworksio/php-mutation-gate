<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\MutantEvent;

$mutant = static fn(MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0),
    '12',
    Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(43)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-"),
    $status,
    Seconds::of(0.1),
);

it('tells of a killed mutant by its id, site, killer, round and the digest of its unit', function () use ($mutant): void {
    $killed = $mutant(MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::sums')));
    $event = MutantEvent::of($killed, quick: true, digest: Digest::of('9c1e'));

    expect($event instanceof MutantEvent ? $event->line() : '')->toBe(sprintf(
        '{"mutant":"%s","site":"src/Money.php:42","status":"killed","by":"MoneyTest::adds","quick":true,"unit":"src/Money.php","digest":"9c1e"}',
        $killed->id()->value(),
    ));
});

it('tells of a survivor by no killer and no digest where none is known', function () use ($mutant): void {
    $survivor = $mutant(MutantStatus::Survived);
    $event = MutantEvent::of($survivor, quick: false, digest: NotGiven::value());

    expect($event instanceof MutantEvent ? $event->line() : '')->toBe(sprintf(
        '{"mutant":"%s","site":"src/Money.php:42","status":"survived","by":null,"quick":false,"unit":"src/Money.php","digest":null}',
        $survivor->id()->value(),
    ));
});

it('tells nothing of a mutant a marker ignored', function () use ($mutant): void {
    expect(MutantEvent::of($mutant(MutantStatus::IgnoredByMarker), quick: true, digest: NotGiven::value()))->toBeInstanceOf(NotGiven::class);
});
