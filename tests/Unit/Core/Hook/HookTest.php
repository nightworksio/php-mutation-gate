<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Hook\HookCall;
use NightWorksIO\MutationGate\Core\Hook\Occupant;

it('is the file git runs for its moment', function (): void {
    expect(Hook::PrePush->file())->toEqual(Path::of('pre-push'))
        ->and(Hook::PreCommit->file())->toEqual(Path::of('pre-commit'));
});

it('writes a shell script that says who wrote it and calls the gate', function (): void {
    $script = Hook::PrePush->script(HookCall::of(Path::root(), Path::of('vendor/bin/mutation-gate')));

    expect($script->text())->toBe(<<<'SH'
        #!/bin/sh
        # Written by `mutation-gate hook install`, and removed by `mutation-gate hook uninstall`.
        exec vendor/bin/mutation-gate pre-push "$@"

        SH);
});

it('knows a hook it wrote from one somebody else wrote, and from none', function (): void {
    $ours = Hook::PreCommit->script(HookCall::of(Path::of('app'), Path::of('vendor/bin/mutation-gate')));

    expect(Hook::PreCommit->occupant($ours))->toBe(Occupant::TheGate)
        ->and(Hook::PrePush->occupant($ours))->toBe(Occupant::TheGate)
        ->and(Hook::PrePush->occupant(Contents::of("#!/bin/sh\nnpm test\n")))->toBe(Occupant::Someone)
        ->and(Hook::PrePush->occupant(Missing::at(Path::of('pre-push'))))->toBe(Occupant::Nobody);
});
