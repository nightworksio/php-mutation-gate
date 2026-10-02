<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Link;

it('names a call in lower case and without its leading backslash, as PHP matches it', function (): void {
    expect(Link::of('\\beforeEach', [])->name())->toBe('beforeeach');
});
