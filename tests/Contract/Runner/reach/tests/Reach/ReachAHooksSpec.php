<?php

declare(strict_types=1);

pest()->beforeEach(function (): void {
    $this->reached = 5;
})->in(__DIR__);

it('registers the hook another test file relies on', function (): void {
    expect($this->reached)->toBe(5);
});
