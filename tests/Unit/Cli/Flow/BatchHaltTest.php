<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\BatchHalt;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The batch of the project's src/Money.php as it began, armed to look at the file every second. */
function moneyBatchHalt(string $project): BatchHalt
{
    Scratch::write($project, 'src/Money.php', "<?php\n\n// as the batch began\n");
    $armed = BatchHalt::armed(Directory::at($project), Units::of(Unit::file(Path::of('src/Money.php'))), Seconds::milliseconds(200));

    return $armed instanceof BatchHalt ? $armed : throw new LogicException('This PHP has no alarm.');
}

/** The alarm the halt armed, rung through the handler PHP would call for it. */
function alarmed(): void
{
    $handler = pcntl_signal_get_handler(SIGALRM);

    if (is_callable($handler)) {
        $handler();
    }
}

it('halts the batch from the first look that finds a unit\'s file saved since the batch began, and handles the alarm as before once disarmed', function (): void {
    $project = Scratch::directory();
    $halt = moneyBatchHalt($project);
    Scratch::write($project, 'src/Money.php', "<?php\n\n// saved\n");
    alarmed();

    expect($halt->disarmed())->toBeTrue()
        ->and(pcntl_signal_get_handler(SIGALRM))->toBe(SIG_DFL);
})->skip(! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');

it('lets a batch whose files no save moved run to its end', function (): void {
    $project = Scratch::directory();
    $halt = moneyBatchHalt($project);
    alarmed();

    expect($halt->disarmed())->toBeFalse();
})->skip(! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');

it('takes a file it cannot read, as the batch began or at a look, as one unlike any it can', function (): void {
    $project = Scratch::directory();
    $halt = moneyBatchHalt($project);
    unlink(sprintf('%s/src/Money.php', $project));
    alarmed();

    expect($halt->disarmed())->toBeTrue();
})->skip(! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');
