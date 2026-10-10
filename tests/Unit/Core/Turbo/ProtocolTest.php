<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Turbo\Protocol;

it('asks the version of the helper its crate declares, so the two never drift apart', function (): void {
    $manifest = (string) file_get_contents(sprintf('%s/turbo/mutation-gate-turbo/Cargo.toml', dirname(__DIR__, 4)));

    expect($manifest)->toContain(sprintf("name = \"%s\"\nversion = \"%s\"\n", Protocol::HELPER, Protocol::HELPER_VERSION));
});

it('speaks the protocol the helper speaks', function (): void {
    $protocol = (string) file_get_contents(sprintf('%s/turbo/mutation-gate-turbo/src/protocol.rs', dirname(__DIR__, 4)));

    expect($protocol)->toContain(sprintf('pub const PROTOCOL: u64 = %d;', Protocol::VERSION));
});
