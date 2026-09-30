<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\SourceRoot;

it('spells a directory as a file URI that every relative path resolves inside', function (string $directory, string $uri): void {
    expect(SourceRoot::at($directory)->uri())->toBe($uri);
})->with([
    'a Unix directory' => ['/home/ci/gate', 'file:///home/ci/gate/'],
    'one with a trailing slash' => ['/home/ci/gate/', 'file:///home/ci/gate/'],
    'one with a space and a hash' => ['/Users/me/My Gate#2', 'file:///Users/me/My%20Gate%232/'],
    'a Windows directory' => ['C:\\work\\gate', 'file:///C:/work/gate/'],
    'the root' => ['/', 'file:///'],
]);
