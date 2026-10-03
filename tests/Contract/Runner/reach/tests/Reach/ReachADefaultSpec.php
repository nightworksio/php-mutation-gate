<?php

declare(strict_types=1);

pest()->beforeEach(function (): void {
    $this->reached = 6;
})->in(__DIR__);

it('registers the hook whose value another test file falls back from', function (): void {
    expect($this->reached)->toBe(6);
});
