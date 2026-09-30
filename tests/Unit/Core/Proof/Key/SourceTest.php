<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinition;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Proof\Key\Source;

$fingerprint = static fn(string $path): Fingerprint => Fingerprint::of(Path::of($path), Digest::of('9c1e'));

it('reads every file outside the tests but the exceptions and the CI definitions it reads as they run', function () use ($fingerprint): void {
    $ci = CiDefinitions::of(
        CiDefinition::at(Path::of('.gitlab/mutation-gate.yml'), Contents::of('image: php')),
        CiDefinition::at(Path::of('ci/template.yml'), Contents::of('stages: [test]')),
    );
    $source = Source::of(
        Fingerprints::of(
            $fingerprint('src/Money.php'),
            $fingerprint('mutation-gate.json'),
            $fingerprint('.gitlab/mutation-gate.yml'),
            $fingerprint('ci/template.yml'),
            $fingerprint('composer.json'),
        ),
        $ci,
        Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate-baseline.json'), Ignored::nothing()),
    );

    expect($source->files())->toEqual(Fingerprints::of($fingerprint('src/Money.php'), $fingerprint('composer.json')))
        ->and($source->ci())->toBe($ci);
});
