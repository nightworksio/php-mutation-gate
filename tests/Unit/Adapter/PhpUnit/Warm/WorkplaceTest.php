<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Job;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('opens with the job in it and no run claimed, and is removed with every file in it', function (): void {
    $directory = sprintf('%s/warm/one', Scratch::directory());
    $workplace = Workplace::at($directory);
    $job = Job::of('/p/vendor/autoload.php', NotGiven::value(), [], NotGiven::value(), []);

    $opened = $workplace->opened($job);
    file_put_contents($workplace->out(0), 'said');
    file_put_contents($workplace->end(0), '{}');
    $workplace->removed();

    expect($opened)->toBeTrue()
        ->and(is_dir($directory))->toBeFalse()
        ->and($workplace->directory())->toBe($directory);
});

it('names each file of the job, each run and each worker apart', function (): void {
    $workplace = Workplace::at('/w');

    expect([$workplace->job(), $workplace->claimed(), $workplace->end(3), $workplace->out(1), $workplace->err(1), $workplace->refused(1)])
        ->toBe(['/w/job.json', '/w/claimed', '/w/3.end', '/w/worker-1.out', '/w/worker-1.err', '/w/refused.1']);
});

it('opens nowhere it cannot make, and removes nothing where there is nothing', function (): void {
    $file = sprintf('%s/file', Scratch::directory());
    file_put_contents($file, '');
    $workplace = Workplace::at(sprintf('%s/under', $file));
    $workplace->removed();

    expect($workplace->opened(Job::of('', NotGiven::value(), [], NotGiven::value(), [])))->toBeFalse();
});
