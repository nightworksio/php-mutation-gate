<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Scratch;

uses()->afterEach(function (): void {
    Scratch::sweep();
})->in(__DIR__);
