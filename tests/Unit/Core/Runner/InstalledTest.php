<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Installed;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

$installed = static fn(): Installed => Installed::fromJson((string) json_encode(['packages' => [
    ['name' => 'a/one', 'version' => '1.0.0', 'source' => ['reference' => 's1'], 'dist' => ['reference' => 'd1']],
    ['name' => 'b/two', 'version' => '2.0.0', 'source' => ['reference' => ''], 'dist' => ['reference' => 'd2']],
    ['name' => 'c/three', 'version' => 3, 'source' => 'not a source'],
    ['name' => ['not a name'], 'version' => '9'],
    'not a package',
]]));

it('names each package\'s version and its source reference, or its dist one without a source one', function () use ($installed): void {
    expect($installed()->versionsOf('b/two', 'a/one', 'c/three'))->toEqual(Versions::of(
        Version::of('b/two', '2.0.0', 'd2'),
        Version::of('a/one', '1.0.0', 's1'),
        Version::of('c/three', '', ''),
    ));
});

it('names the packages it does not list, and leaves them out of the versions', function () use ($installed): void {
    expect($installed()->missing('a/one', 'z/none', 'y/none'))->toBe(['z/none', 'y/none'])
        ->and($installed()->versionsOf('z/none', 'a/one'))->toEqual(Versions::of(Version::of('a/one', '1.0.0', 's1')));
});

it('lists no package in text that is not JSON, or that holds no list of packages', function (): void {
    expect(Installed::fromJson('{')->missing('a/one'))->toBe(['a/one'])
        ->and(Installed::fromJson('{"packages": "none"}')->missing('a/one'))->toBe(['a/one'])
        ->and(Installed::fromJson('')->missing('a/one'))->toBe(['a/one']);
});
