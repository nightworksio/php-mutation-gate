<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\WatchWaits;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\Unwatched;
use NightWorksIO\MutationGate\Tests\Fakes\NoticesChangesFake;

/**
 * Waits armed on the daemon's notices, every two seconds, each wait of their own kept and saying to go on, each line
 * they say kept.
 *
 * @param ArrayObject<int, Seconds> $waited
 * @param ArrayObject<int, string>  $said
 */
function watchWaitsOn(NoticesChangesFake|NotGiven $notices, ArrayObject $waited, ArrayObject $said): WatchWaits
{
    return WatchWaits::armed(
        static function (Seconds $wait) use ($waited): bool {
            $waited->append($wait);

            return true;
        },
        $notices,
        ['src', 'tests'],
        Seconds::of(2),
        static function (string $line) use ($said): void {
            $said->append($line);
        },
    );
}

it('waits on the daemon, which tells of a change past the generation it armed at', function (): void {
    $notices = new NoticesChangesFake([3, 3, 4]);
    $waited = new ArrayObject();
    $said = new ArrayObject();
    $waits = watchWaitsOn($notices, $waited, $said);

    expect($waits->told())->toBeFalse()
        ->and($waits->waited())->toBeTrue()
        ->and($waits->told())->toBeFalse()
        ->and($waited->getArrayCopy())->toBe([])
        ->and($said->getArrayCopy())->toBe([])
        ->and($notices->changes)->toEqual([
            [['src', 'tests'], 0, Seconds::none()],
            [['src', 'tests'], 3, Seconds::none()],
            [['src', 'tests'], 3, Seconds::of(2)],
            [['src', 'tests'], 4, Seconds::none()],
        ]);
});

it('tells of a change the daemon saw since it was last asked', function (): void {
    $waited = new ArrayObject();
    $said = new ArrayObject();
    $waits = watchWaitsOn(new NoticesChangesFake([1, 2]), $waited, $said);

    expect($waits->told())->toBeTrue()
        ->and($waits->told())->toBeFalse();
});

it('waits itself, saying so once, where the daemon watches no file as the watch starts', function (): void {
    $notices = new NoticesChangesFake([Unwatched::because('inotify has no watch left')]);
    $waited = new ArrayObject();
    $said = new ArrayObject();
    $waits = watchWaitsOn($notices, $waited, $said);

    expect($waits->waited())->toBeTrue()
        ->and($waits->told())->toBeInstanceOf(NotGiven::class)
        ->and($waited->getArrayCopy())->toEqual([Seconds::of(2)])
        ->and($said->getArrayCopy())->toBe(['outbreak serve watches no file (inotify has no watch left), so watch looks at the files every 2s.'])
        ->and($notices->changes)->toHaveCount(1);
});

it('waits itself, saying so once, where the daemon stops answering', function (): void {
    $waited = new ArrayObject();
    $said = new ArrayObject();
    $waits = watchWaitsOn(new NoticesChangesFake([1, NotWritten::because('the socket is gone')]), $waited, $said);

    expect($waits->waited())->toBeTrue()
        ->and($waits->waited())->toBeTrue()
        ->and($waited->getArrayCopy())->toEqual([Seconds::of(2), Seconds::of(2)])
        ->and($said->getArrayCopy())->toBe(['outbreak serve stopped answering (the socket is gone), so watch looks at the files every 2s.']);
});

it('waits itself, saying nothing, where no daemon answers as the watch starts or none serves the project', function (): void {
    $waited = new ArrayObject();
    $said = new ArrayObject();
    $unanswered = watchWaitsOn(new NoticesChangesFake([NotWritten::because('no socket')]), $waited, $said);
    $unanswered->found();
    $unserved = watchWaitsOn(NotGiven::value(), $waited, $said);

    expect($unanswered->waited())->toBeTrue()
        ->and($unserved->waited())->toBeTrue()
        ->and($waited->getArrayCopy())->toEqual([Seconds::of(2), Seconds::of(2)])
        ->and($said->getArrayCopy())->toBe([]);
});

it('stops trusting the daemon where a look found a change it told of neither in the last wait nor in one more', function (): void {
    $waited = new ArrayObject();
    $said = new ArrayObject();
    $waits = watchWaitsOn(new NoticesChangesFake([1, 1, 1]), $waited, $said);
    $waits->waited();
    $waits->found();

    expect($said->getArrayCopy())->toBe(['outbreak serve did not tell of a change a look found, so watch looks at the files every 2s.'])
        ->and($waits->told())->toBeInstanceOf(NotGiven::class);
});

it('keeps trusting the daemon where it told of the change a look found', function (): void {
    $waited = new ArrayObject();
    $said = new ArrayObject();
    $notices = new NoticesChangesFake([1, 2]);
    $waits = watchWaitsOn($notices, $waited, $said);
    $waits->waited();
    $waits->found();

    expect($said->getArrayCopy())->toBe([])
        ->and($notices->changes)->toHaveCount(2);
});
