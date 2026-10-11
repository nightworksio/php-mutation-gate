<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    // Killers logs this process's errors to the mutant's own file, as it does in a mutant's own process.
    ini_restore('error_log');
    ini_restore('log_errors');
    Scratch::sweep();
});

it('keeps in the mutant\'s log the memory limit its process ran out of, where PHP shows and logs its errors nowhere', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $php = new Process([PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', sprintf(<<<'PHP_WRAP'
    require %s;
    NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers::listening(%s, '/tmp/mutations/abc', new PHPUnit\Event\Facade(), original: false, loaded: NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded::of([]), heartbeat: NightWorksIO\MutationGate\Adapter\Pest\Recording\Heartbeat::onErrorOutput());
    register_shutdown_function(static function (): void {
        $held = [];
        for ($megabytes = 0; $megabytes < 64; $megabytes++) {
            $held[] = str_repeat('x', 1048576);
        }
    });
    $held = [];
    while (true) {
        $held[] = str_repeat('x', 16);
    }
    PHP_WRAP, var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 5)), return: true), var_export($results, return: true))]);
    $php->run();

    expect($php->getOutput())->toBe('')
        ->and((string) file_get_contents(Recorder::errorsBeside($results, '/tmp/mutations/abc')))
        ->toContain('Allowed memory size of 33554432 bytes exhausted');
});
