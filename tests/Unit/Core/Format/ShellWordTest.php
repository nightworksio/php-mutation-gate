<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\ShellWord;

it('leaves a word the shell reads as it is, and quotes any other, a single quote among it', function (): void {
    expect(ShellWord::of('vendor/bin/mutation-gate_2.0-rc'))->toBe('vendor/bin/mutation-gate_2.0-rc')
        ->and(ShellWord::of('my dir'))->toBe("'my dir'")
        ->and(ShellWord::of("it's"))->toBe("'it'\\''s'")
        ->and(ShellWord::of('$HOME'))->toBe("'\$HOME'")
        ->and(ShellWord::of(''))->toBe("''");
});
