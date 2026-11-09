<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Report\Commented;
use NightWorksIO\MutationGate\Core\Report\MarkdownItems;

it('shows as many of each list and characters of a text as the comment always has, where the config says nothing', function (): void {
    expect(array_map(static fn(Cap $cap): int => $cap->standard(), Cap::cases()))->toBe([
        Commented::MOST,
        Commented::MOST,
        Commented::MOST,
        Commented::MOST,
        5,
        MarkdownItems::SHOWN_CHARACTERS,
    ]);
});
