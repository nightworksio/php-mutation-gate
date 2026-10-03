<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Expiry;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('says where an ignore that ends stands on the day of a run', function (string $expires, Expiry $expiry): void {
    $day = Day::of($expires);

    expect($day instanceof Day ? Expiry::of($day, new DateTimeImmutable(Configs::NOW)) : $day)->toBe($expiry);
})->with([
    'its day was yesterday' => ['2026-09-29', Expiry::Expired],
    'its day is today' => ['2026-09-30', Expiry::Expiring],
    'its day is the last of the notice' => ['2026-10-14', Expiry::Expiring],
    'its day is past the notice' => ['2026-10-15', Expiry::Lasting],
]);
