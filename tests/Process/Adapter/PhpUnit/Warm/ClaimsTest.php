<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Claims;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Job;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGate\Tests\Support\WarmClaims;

afterEach(function (): void {
    Scratch::sweep();
});

it('claims each run once where workers in processes of their own claim at once', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $job = WarmClaims::job(2000, NotGiven::value());
    $workplace->opened($job);
    $script = sprintf('%s/claiming.php', Scratch::directory());
    file_put_contents($script, sprintf(
        "<?php\nrequire %s;\n\$workplace = %s::at(\$argv[1]);\n"
        . "\$claims = %s::of(\$workplace, %s::read((string) file_get_contents(\$workplace->job())));\n"
        . "for (\$at = \$claims->next(); is_int(\$at); \$at = \$claims->next()) {\n    echo sprintf('%%d%%s', \$at, PHP_EOL);\n}\n",
        var_export(Tree::at('vendor/autoload.php'), return: true),
        Workplace::class,
        Claims::class,
        Job::class,
    ));
    $command = sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg($workplace->directory()));
    $printed = [];
    exec(sprintf('(%1$s & %1$s & %1$s & %1$s & wait)', $command), $printed);
    $claimed = array_map(intval(...), $printed);
    sort($claimed);

    expect($claimed)->toBe(range(0, 1999));
});
