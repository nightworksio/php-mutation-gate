<?php

declare(strict_types=1);

$GLOBALS['reachShared'] = 5;

it('sets the global another test file reads', function (): void {
    expect($GLOBALS['reachShared'])->toBe(5);
});
