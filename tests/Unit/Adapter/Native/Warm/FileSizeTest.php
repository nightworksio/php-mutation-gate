<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Warm\FileSize;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads a file\'s size as it stands now, after another process has grown it since it was last read', function (): void {
    $file = sprintf('%s/grows', Scratch::directory());
    file_put_contents($file, 'one');
    $grower = proc_open(['sh', '-c', 'sleep 0.3; printf " two" >> "$0"', $file], [], $pipes);
    $before = FileSize::now($file);
    $grown = is_resource($grower) ? proc_close($grower) : -1;
    $sizes = [$grown, $before, FileSize::now($file), FileSize::now(sprintf('%s.missing', $file))];

    expect($sizes)->toBe([0, 3, 7, 0]);
});
