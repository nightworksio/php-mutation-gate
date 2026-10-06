<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Claims;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Job;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\WarmRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** A job of this many runs, which no run starts in after the end. */
function claimsJob(int $runs, float|NotGiven $end): Job
{
    return Job::of('/p/vendor/autoload.php', NotGiven::value(), [], $end, array_fill(0, $runs, WarmRun::of([], [], 1.0, '', '', '')));
}

it('claims each run once, in order, across every worker, and none once all are claimed', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $job = claimsJob(3, NotGiven::value());
    $workplace->opened($job);
    $first = Claims::of($workplace, $job);
    $second = Claims::of($workplace, $job);

    expect([$first->next(), $second->next(), $first->next(), $second->next(), $first->next()])
        ->toEqual([0, 1, 2, NotGiven::value(), NotGiven::value()]);
});

it('claims no run once the job\'s end has come', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $job = claimsJob(3, microtime(as_float: true) - 1.0);
    $workplace->opened($job);

    expect(Claims::of($workplace, $job)->next())->toEqual(NotGiven::value())
        ->and(file_get_contents($workplace->claimed()))->toBe('0');
});

it('claims nothing where the count of claims cannot be opened', function (): void {
    $job = claimsJob(1, NotGiven::value());

    expect(Claims::of(Workplace::at(sprintf('%s/nowhere', Scratch::directory())), $job)->next())->toEqual(NotGiven::value());
});

it('claims each run once where workers in processes of their own claim at once', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $job = claimsJob(2000, NotGiven::value());
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
