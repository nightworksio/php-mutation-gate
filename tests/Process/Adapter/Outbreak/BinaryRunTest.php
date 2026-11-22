<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\BinaryRun;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

$holds = ['holds:src/Adapter/Outbreak/BinaryRun.php'];

/** What a PHP process with this temporary directory, running a script of its own so its lines are recorded, says starting the binary at this path in this directory came to. */
function binaryRunStarted(string $temporary, string $binary, string $directory): string
{
    $script = <<<'PHP'
        <?php
        require $argv[1] . '/vendor/autoload.php';
        chdir($argv[3]);
        $started = \NightWorksIO\MutationGate\Adapter\Outbreak\BinaryRun::started($argv[2], ['contract'], '', \NightWorksIO\MutationGate\Core\Time\Seconds::of(5.0));
        echo $started instanceof \NightWorksIO\MutationGate\Core\NotWritten ? $started->why() : 'started';
        PHP;
    $scripts = Scratch::directory();
    Scratch::write($scripts, 'started.php', $script);
    $process = new Process([PHP_BINARY, '-d', sprintf('sys_temp_dir=%s', $temporary), sprintf('%s/started.php', $scripts), Tree::root(), $binary, $directory], env: [...Command::unset(), 'TMPDIR' => $temporary]);
    $process->run();

    return trim(sprintf('%s%s', $process->getOutput(), $process->getErrorOutput()));
}

it('cannot start the binary where no file can be made to hand it its input', function (): void {
    $missing = sprintf('%s/missing', Scratch::directory());

    expect(binaryRunStarted($missing, '/bin/true', Tree::root()))->toBe('No file could be made to hand Outbreak its input.');
})->group(...$holds);

it('says why the binary could not be started where its working directory is gone', function (): void {
    $gone = Scratch::directory();
    $here = (string) getcwd();
    chdir($gone);
    rmdir($gone);

    $started = BinaryRun::started('/bin/true', ['contract'], '', Seconds::of(5.0));
    chdir($here);

    expect($started instanceof BinaryRun ? 'started' : $started->why())->toContain('cwd');
})->group(...$holds);

it('cannot start the binary where this process can open no file to hand it its input', function (): void {
    $limit = posix_getrlimit();
    $soft = is_array($limit) ? $limit['soft openfiles'] : 'unlimited';
    $hard = is_array($limit) ? $limit['hard openfiles'] : 'unlimited';
    posix_setrlimit(POSIX_RLIMIT_NOFILE, 0, $hard === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $hard);

    try {
        $started = BinaryRun::started('/bin/true', ['contract'], '', Seconds::of(5.0));
    } finally {
        posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $soft, $hard === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $hard);
    }

    expect($started)->toEqual(NotWritten::because('No file could be made to hand Outbreak its input.'));
})->group(...$holds);

it('takes no answer that names no contract, or one that is not a number', function (string $answer): void {
    $ran = BinaryRun::ran(OutbreakStandIn::answering($answer)->binary(), ['map', 'split'], '', Seconds::of(5.0));

    expect($ran)->toEqual(NotWritten::because($answer));
})->with(['no contract' => ['{"written":2}'], 'a contract in words' => ['{"outbreak":"one"}']])->group(...$holds);
