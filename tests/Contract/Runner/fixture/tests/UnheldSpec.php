<?php

declare(strict_types=1);

// Covers the line src/Shapes.php holds without holding it, and would kill its
// mutant, so no run of the group may select it.
it('covers the held line without holding it', function (): void {
    expect(seen('unheld'))->toBeTrue();
});
