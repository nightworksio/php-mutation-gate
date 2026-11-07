<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Recheck\RecheckJobs;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$files = static fn(int $count): Paths => Paths::of(...array_map(
    static fn(int $file): Path => Path::of(sprintf('src/F%d.php', $file)),
    $count === 0 ? [] : range(1, $count),
));

it('runs a job per file, at most survivorsFirst.shards, each given its budget and ten minutes to set up', function () use ($files): void {
    $first = SurvivorsFirst::standard();

    expect(RecheckJobs::for($files(2), $first)->encode())->toBe('{"shares":[1,2],"minutes":30}')
        ->and(RecheckJobs::for($files(9), $first)->encode())->toBe('{"shares":[1,2,3,4],"minutes":30}')
        ->and(RecheckJobs::for($files(9), $first)->count())->toBe(SurvivorsFirst::SHARDS)
        ->and(RecheckJobs::for($files(9), SurvivorsFirst::of(budget: Seconds::of(61), shards: 2))->encode())
        ->toBe('{"shares":[1,2],"minutes":12}');
});

it('runs no job where the re-check takes no survivor, and one, to say why, where they cannot be told', function () use ($files): void {
    $first = SurvivorsFirst::standard();

    expect(RecheckJobs::none($first)->encode())->toBe('{"shares":[],"minutes":30}')
        ->and(RecheckJobs::for($files(0), $first)->count())->toBe(0)
        ->and(RecheckJobs::unsure($first)->encode())->toBe('{"shares":[1],"minutes":30}');
});
