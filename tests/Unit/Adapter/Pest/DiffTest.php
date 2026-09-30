<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Diff;

it('turns the diff Pest keeps into a plain unified diff', function (): void {
    $pest = "\n  <fg=gray>     {</>\n"
        . "  <fg=red>-        return \$amount \\> 100;</>\n"
        . "  <fg=green>+        return \$amount \\>= 100;</>\n"
        . "  <fg=gray>     }</>\n  <fg=gray></>\n";

    expect(Diff::fromPest($pest))
        ->toBe("@@ @@\n     {\n-        return \$amount > 100;\n+        return \$amount >= 100;\n     }");
});

it('keeps a line Pest did not indent as it is', function (): void {
    expect(Diff::fromPest("-x;\n+y;"))->toBe("@@ @@\n-x;\n+y;");
});
