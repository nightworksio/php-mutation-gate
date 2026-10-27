<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Verdict\Provenance;

it('names the gate as it was started in every command it prints, the installed one until told otherwise', function (): void {
    $provenance = Provenance::of(Version::of('nightworksio/mutation-gate', 'v1.0.0', 'abc'), Path::of('mutation-gate.baseline.json'));

    expect($provenance->command()->gate())->toBe('vendor/bin/mutation-gate')
        ->and($provenance->startedAs(GateCommand::byPhp(Path::of('tools/mutation-gate')))->command()->gate())
        ->toBe('php tools/mutation-gate');
});
