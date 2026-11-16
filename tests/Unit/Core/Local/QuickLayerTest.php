<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Local\QuickLayer;

it('lays split streams sharded by source file and no static check over the config', function (): void {
    expect(Json::object(...QuickLayer::members())->line())
        ->toBe('{"splitStream":{"enabled":true,"sharding":"source"},"staticCheck":{"tool":"none"}}');
});
