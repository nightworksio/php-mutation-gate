<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\MisreadSetting;
use NightWorksIO\MutationGate\Core\Config\Definition\OptionJson;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Registry\Origin;

it('writes a path as its path from the project, and a list of them item by item', function (): void {
    expect(OptionJson::of(Path::of('./ci/cache/')))->toBe('ci/cache')
        ->and(OptionJson::of(Listed::of(Path::of('app'), Path::of('lib/'))))->toEqual(Json::items('app', 'lib'));
});

it('keeps a value JSON writes, and an option left out, as they are', function (): void {
    expect(OptionJson::of('us-east-1'))->toBe('us-east-1')
        ->and(OptionJson::of(Json::object()))->toEqual(Json::object())
        ->and(OptionJson::of(Absent::setting()))->toEqual(Absent::setting());
});

it('refuses an option that is neither, as a definition that misreads itself', function (): void {
    expect(static fn(): mixed => OptionJson::of(Origin::of('acme/gate')))->toThrow(MisreadSetting::class)
        ->and(static fn(): mixed => OptionJson::of(Listed::of(Origin::of('acme/gate'))))->toThrow(MisreadSetting::class);
});
