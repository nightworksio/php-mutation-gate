<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads a date as the day it names', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.neon', <<<'NEON'
        ignores:
            entries:
                - {mutant: '3f9a1c2b7d04', reason: Equivalent, expires: 2027-03-31}
        NEON);
    $document = new NeonConfig()->load(Path::of(sprintf('%s/mutation-gate.neon', $project)));

    expect($document instanceof Document ? $document->json() : $document)
        ->toBe('{"ignores":{"entries":[{"mutant":"3f9a1c2b7d04","reason":"Equivalent","expires":"2027-03-31"}]}}');
});

it('refuses an entity, which is not data', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.neon', "runner: Pest(fast)\n");
    $file = sprintf('%s/mutation-gate.neon', $project);

    expect(new NeonConfig()->load(Path::of($file)))
        ->toEqual(CannotJudge::because(sprintf(
            '%s holds an object, Nette\Neon\Entity, and a config holds data only.',
            $file,
        )));
});

it('cannot judge a file that is not NEON, naming it', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.neon', "runner: [pest\n");
    $file = sprintf('%s/mutation-gate.neon', $project);
    $loaded = new NeonConfig()->load(Path::of($file));

    expect($loaded instanceof CannotJudge ? $loaded->why() : '')->toStartWith(sprintf('%s is not NEON: ', $file));
});

it('cannot judge a file that is not there', function (): void {
    expect(new NeonConfig()->load(Path::of('/nowhere/mutation-gate.neon')))
        ->toEqual(CannotJudge::because('/nowhere/mutation-gate.neon could not be read.'));
});

it('writes a config as NEON that reads back as the same config', function (): void {
    $config = [
        'runner' => ['use' => 'Acme\\Runner', 'with' => ['workers' => 4]],
        'trees' => [['path' => 'src', 'floor' => 83.5]],
        'ignores' => ['entries' => [['mutant' => '123456789012', 'reason' => 'Equivalent', 'expires' => '2027-03-31']]],
        'costs' => ['secondsPerLine' => ['' => 0.2]],
    ];
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.neon', new NeonConfig()->render(Configs::document($config)));
    $read = new NeonConfig()->load(Path::of(sprintf('%s/mutation-gate.neon', $project)));

    expect(new NeonConfig()->render(Configs::document(['runner' => 'pest'])))->toBe("runner: pest\n")
        ->and($read instanceof Document ? json_decode($read->json(), associative: true) : $read)->toBe($config);
});
