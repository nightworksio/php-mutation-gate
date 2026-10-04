<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\ShellPattern;

it('covers the path itself, a file inside it as a directory, and a file it matches as a pattern', function (): void {
    expect(ShellPattern::covers('/work/src', '/work/src'))->toBeTrue()
        ->and(ShellPattern::covers('/work/src', '/work/src/Money.php'))->toBeTrue()
        ->and(ShellPattern::covers('/work/src/', '/work/src/Money.php'))->toBeTrue()
        ->and(ShellPattern::covers('/work/src/*.php', '/work/src/Money.php'))->toBeTrue()
        ->and(ShellPattern::covers('/work/src/Mon?y.php', '/work/src/Money.php'))->toBeTrue()
        ->and(ShellPattern::covers('/work/src/[M]oney.php', '/work/src/Money.php'))->toBeTrue();
});

it('covers no file beside the path, nor one a name matches only as a pattern', function (): void {
    expect(ShellPattern::covers('/work/src', '/work/srcs/Money.php'))->toBeFalse()
        ->and(ShellPattern::covers('/work/src/*.php', '/work/lib/Money.php'))->toBeFalse()
        ->and(ShellPattern::covers('/work/s.c', '/work/sxc'))->toBeFalse();
});
