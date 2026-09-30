<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\MisreadSetting;

it('names the setting and the type it was read as', function (): void {
    expect(MisreadSetting::as('shards', 'a part of a layer')->getMessage())
        ->toBe('The setting "shards" was read as a part of a layer, which its definition does not make.');
});
