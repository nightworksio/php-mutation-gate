<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Job;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\WarmRun;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reads back every run and setting it wrote, in order', function (): void {
    $runs = [
        WarmRun::of(['/p/vendor/bin/phpunit', '--no-progress'], ['MUTATION_GATE_RESULTS' => '/r/1'], 12.5, '/p/src/A.php', '/m/1.php', '/g/1'),
        WarmRun::of(['/p/vendor/bin/phpunit'], [], 5.0, '/p/src/B.php', '/m/2.php', '/g/2'),
    ];
    $job = Job::of('/p/vendor/autoload.php', '/p/phpunit.xml', ['/p/src/A.php', '/p/src/B.php'], 1700000000.25, $runs);
    $read = Job::read($job->written()->line());

    expect($read)->toEqual($job)
        ->and($read->count())->toBe(2)
        ->and($read->run(1)->original())->toBe('/p/src/B.php')
        ->and($read->run(0)->argv())->toBe(['/p/vendor/bin/phpunit', '--no-progress'])
        ->and($read->run(0)->environment())->toBe(['MUTATION_GATE_RESULTS' => '/r/1'])
        ->and($read->run(0)->limit())->toBe(12.5)
        ->and([$read->run(0)->mutated(), $read->run(0)->guard()])->toBe(['/m/1.php', '/g/1'])
        ->and([$read->autoloader(), $read->config(), $read->mutated(), $read->end()])
        ->toBe(['/p/vendor/autoload.php', '/p/phpunit.xml', ['/p/src/A.php', '/p/src/B.php'], 1700000000.25]);
});

it('writes no config and no end where it has none, and reads them back as none', function (): void {
    $job = Job::of('/p/vendor/autoload.php', NotGiven::value(), [], NotGiven::value(), []);
    $read = Job::read($job->written()->line());

    expect($job->written()->line())->toBe('{"autoloader":"/p/vendor/autoload.php","mutated":[],"runs":[]}')
        ->and($read->config())->toEqual(NotGiven::value())
        ->and($read->end())->toEqual(NotGiven::value())
        ->and($read->count())->toBe(0);
});
