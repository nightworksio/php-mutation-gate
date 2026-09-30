<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\SearchPath;

it('puts the running PHP\'s directory before the path a process would inherit', function (): void {
    expect(SearchPath::phpFirst('/usr/bin'))->toBe(sprintf('%s%s/usr/bin', dirname(PHP_BINARY), PATH_SEPARATOR))
        ->and(SearchPath::VARIABLE)->toBe('PATH');
});
