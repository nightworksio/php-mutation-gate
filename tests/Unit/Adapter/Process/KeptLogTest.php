<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\ErrorOutputLog;
use NightWorksIO\MutationGate\Adapter\Process\KeptLog;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv(ProcessLog::KEPT);
    Scratch::sweep();
});

it('says each line on its log, and keeps it too in the file the variable names while it names one', function (): void {
    $directory = Scratch::directory();
    $log = KeptLog::over(ErrorOutputLog::to(sprintf('%s/log', $directory)));

    $log->say('before');
    putenv(sprintf('%s=%s/kept', ProcessLog::KEPT, $directory));
    $log->say('one');
    $log->say('two');
    putenv(sprintf('%s=', ProcessLog::KEPT));
    $log->say('after');

    expect(file_get_contents(sprintf('%s/log', $directory)))->toBe("before\none\ntwo\nafter\n")
        ->and(file_get_contents(sprintf('%s/kept', $directory)))->toBe("one\ntwo\n");
});
