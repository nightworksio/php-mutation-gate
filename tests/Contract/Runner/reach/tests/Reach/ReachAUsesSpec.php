<?php

declare(strict_types=1);

trait ReachUsed
{
    protected int $reached = 5;
}

uses(ReachUsed::class)->in(__DIR__);

it('makes every test of its directory use a trait another test file relies on', function (): void {
    expect($this->reached)->toBe(5);
});
