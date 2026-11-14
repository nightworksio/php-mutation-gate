<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\PrintedDeclarations;
use NightWorksIO\MutationGate\Adapter\Outbreak\OpArrays;
use NightWorksIO\MutationGate\Adapter\Outbreak\ProvenBeforeRun;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;

it('prints a program\'s declarations as its printer does, and none of a program that does not parse', function (): void {
    $proving = new ProvenBeforeRun(new OpArrays(PHP_BINARY, '/nowhere/outbreak.so', sys_get_temp_dir(), 1.0), new PrintedDeclarations());
    $program = Contents::of("<?php\nfinal class Money { public function add(int \$a): int { return \$a + 1; } }\n");

    expect($proving->declarations($program))->toBe(new PrintedDeclarations()->declarations($program))
        ->and($proving->declarations($program))->toBeString()
        ->and($proving->declarations(Contents::of('<?php final class {')))->toBeInstanceOf(NotGiven::class);
});
