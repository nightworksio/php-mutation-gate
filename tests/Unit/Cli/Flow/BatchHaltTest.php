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
})->skip(fn(): bool => ! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');

it('lets a batch whose files no save moved run to its end', function (): void {
    $project = Scratch::directory();
    $halt = moneyBatchHalt($project);
    alarmed();

    expect($halt->disarmed())->toBeFalse();
})->skip(fn(): bool => ! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');

it('takes a file it cannot read, as the batch began or at a look, as one unlike any it can', function (): void {
    $project = Scratch::directory();
    $halt = moneyBatchHalt($project);
    unlink(sprintf('%s/src/Money.php', $project));
    alarmed();

    expect($halt->disarmed())->toBeTrue();
})->skip(fn(): bool => ! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');

it('sets its alarm every so often, a whole second at the least, rounding up', function (float $every, int $seconds): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', "<?php\n");
    $halt = BatchHalt::armed(Directory::at($project), Units::of(Unit::file(Path::of('src/Money.php'))), Seconds::of($every));
    $set = pcntl_alarm(0);

    expect($halt instanceof BatchHalt && ! $halt->disarmed() ? $set : 0)->toBe($seconds);
})->with([
    'none' => [0.0, 1],
    'a fifth of a second' => [0.2, 1],
    'up from 1.4 seconds' => [1.4, 2],
    'up from 1.5 seconds' => [1.5, 2],
])->skip(fn(): bool => ! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');

it('sets its next alarm at each look, and leaves none and the halt\'s signal as it was once disarmed', function (): void {
    $halt = moneyBatchHalt(Scratch::directory());
    pcntl_alarm(0);
    alarmed();
    $next = pcntl_alarm(5);
    $halt->disarmed();

    expect($next)->toBe(1)
        ->and(pcntl_alarm(0))->toBe(0)
        ->and(pcntl_signal_get_handler(SIGUSR2))->toBe(SIG_DFL);
})->skip(fn(): bool => ! function_exists('pcntl_alarm'), 'a batch is halted only where this PHP has an alarm');
