<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\LastError;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('names the file the last error PHP raised stands in, and none once there is none', function (): void {
    $reporting = error_reporting(E_ALL & ~E_WARNING);
    set_error_handler(static fn(): bool => false);
    $read = file_get_contents(sprintf('%s/missing.txt', Scratch::directory()));
    restore_error_handler();
    error_reporting($reporting);
    $raised = LastError::file();
    error_clear_last();

    expect($read)->toBeFalse()
        ->and($raised)->toBe(__FILE__)
        ->and(LastError::file())->toBe('');
});
