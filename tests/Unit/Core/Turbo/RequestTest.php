<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Turbo\Request;

it('holds the text it was written as', function (): void {
    expect(Request::ofText('{"protocol":1}')->text())->toBe('{"protocol":1}');
});
