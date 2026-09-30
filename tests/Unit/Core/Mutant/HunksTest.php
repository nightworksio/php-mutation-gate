<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Hunks;

it('reads the lines after the first hunk, leaving the header out', function (): void {
    $diff = "--- a/src/Money.php\n+++ b/src/Money.php\n@@ -3,1 +3,1 @@\n-return \$a + \$b;\n+return \$a - \$b;";

    expect(Hunks::linesOf($diff))->toBe(['-return $a + $b;', '+return $a - $b;']);
});

it('reads every line of a diff with no hunk', function (): void {
    expect(Hunks::linesOf("-return 1;\n+return 2;"))->toBe(['-return 1;', '+return 2;']);
});

it('keeps the lines of a later hunk, its own line among them', function (): void {
    expect(Hunks::linesOf("@@ -1 +1 @@\n-a\n+b\n@@ -9 +9 @@\n-c"))->toBe(['-a', '+b', '@@ -9 +9 @@', '-c']);
});
