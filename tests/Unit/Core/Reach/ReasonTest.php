<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Reach\Reason;

it('is the sentence printed for a decision', function (): void {
    expect(Reason::that('`README.md` reaches nothing by itself.')->text())->toBe('`README.md` reaches nothing by itself.');
});
