<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs from its binary, with exit code 2 for a command that cannot judge', function (): void {
    $process = new Process([PHP_BINARY, 'bin/mutation-gate', 'triage', 'src', '--repeat=1'], Tree::root());
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toBe("--repeat takes a whole number of 2 or more, not \"1\".\n");
});

it('raises its own memory_limit to what its ledgers may need, and doctor says where PHP will not let it', function (): void {
    $doctor = static function (string ...$settings): string {
        $process = new Process([PHP_BINARY, ...$settings, 'bin/mutation-gate', 'doctor', '--format=json'], Tree::root());
        $process->run();

        return $process->getOutput();
    };

    $raised = $doctor('-d', 'memory_limit=64M');

    expect($raised)->toContain('"findings"')
        ->and(str_contains($raised, 'memory-limit-low'))->toBeFalse()
        ->and($doctor('-d', 'memory_limit=64M', '-d', 'disable_functions=ini_set'))->toContain('"slug": "memory-limit-low"');
});

it('raises a lower memory_limit to what its ledgers may need, and leaves a higher one or none alone', function (
    string $given,
    string $ended,
): void {
    $watch = Scratch::directory();
    Scratch::write($watch, 'limit.php', <<<'PHP'
        <?php register_shutdown_function(static function (): void {
            fwrite(STDERR, sprintf('memory_limit=%s', ini_get('memory_limit')));
        });
        PHP);
    $process = new Process(
        [PHP_BINARY, '-d', sprintf('memory_limit=%s', $given), '-d', sprintf('auto_prepend_file=%s/limit.php', $watch), 'bin/mutation-gate', '--version'],
        Tree::root(),
    );
    $process->run();

    expect($process->getErrorOutput())->toEndWith(sprintf('memory_limit=%s', $ended));
})->with([
    'a lower limit' => ['64M', '1578217728'],
    'a higher limit' => ['2G', '2G'],
    'no limit' => ['-1', '-1'],
]);
