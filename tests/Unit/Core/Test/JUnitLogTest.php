<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\JUnitLog;

it('names the JUnit log a runner writes junit.xml', function (): void {
    expect(JUnitLog::NAME)->toBe('junit.xml');
});
