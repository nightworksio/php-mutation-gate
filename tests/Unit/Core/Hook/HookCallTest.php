<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Hook\HookCall;

it('calls the gate straight from the top of the working tree, passing git\'s arguments on', function (): void {
    $call = HookCall::of(Path::root(), Path::of('vendor/bin/mutation-gate'));

    expect($call->script(Hook::PrePush))->toBe("exec vendor/bin/mutation-gate pre-push \"\$@\"\n")
        ->and($call->line(Hook::PrePush))->toBe('vendor/bin/mutation-gate pre-push "$@" || exit 1')
        ->and($call->line(Hook::PreCommit))->toBe('vendor/bin/mutation-gate pre-commit "$@" || exit 1');
});

it('enters a project below the top first, and from someone else\'s hook leaves its directory as it was', function (): void {
    $call = HookCall::of(Path::of('packages/app'), Path::of('vendor/bin/mutation-gate'));

    expect($call->script(Hook::PrePush))->toBe("cd packages/app || exit 1\nexec vendor/bin/mutation-gate pre-push \"\$@\"\n")
        ->and($call->line(Hook::PrePush))->toBe('(cd packages/app && vendor/bin/mutation-gate pre-push "$@") || exit 1');
});

it('quotes a path the shell would read otherwise', function (): void {
    $call = HookCall::of(Path::of("my app's dir"), Path::of('$HOME/vendor/bin/mutation-gate'));

    expect($call->script(Hook::PreCommit))->toBe(
        "cd 'my app'\\''s dir' || exit 1\nexec '\$HOME/vendor/bin/mutation-gate' pre-commit \"\$@\"\n",
    );
});
