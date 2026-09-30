<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Format\Json;

it('expects an object', function (): void {
    expect(Section::options(Json::object())->expected())->toBe('an object');
});
