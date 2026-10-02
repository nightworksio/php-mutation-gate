<?php

declare(strict_types=1);

describe('a group whose body sets a global as the file loads', function (): void {
    $GLOBALS['reachDescribed'] = 6;

    it('sets the global another test file falls back from', function (): void {
        expect($GLOBALS['reachDescribed'])->toBe(6);
    });
});
