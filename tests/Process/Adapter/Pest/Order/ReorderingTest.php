<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\Reordering;
use NightWorksIO\MutationGate\Tests\Support\Reorderings;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('adds only options PHPUnit takes without a deprecation, a notice or a warning, any of which a project can fail its run on', function (): void {
    $order = Reorderings::directory();
    $added = array_slice(Reordering::of(['vendor/bin/pest'], $order, '/tmp/mutations/abc'), 1);
    $php = new Process([PHP_BINARY, '-r', sprintf(<<<'PHP_WRAP'
    require %s;
    PHPUnit\Event\Facade::instance()->registerTracer(new class implements PHPUnit\Event\Tracer\Tracer {
        public function trace(PHPUnit\Event\Event $event): void
        {
            $issue = $event instanceof PHPUnit\Event\TestRunner\DeprecationTriggered
                || $event instanceof PHPUnit\Event\TestRunner\NoticeTriggered
                || $event instanceof PHPUnit\Event\TestRunner\WarningTriggered;
            echo $issue ? $event->message() . "\n" : '';
        }
    });
    new PHPUnit\TextUI\CliArguments\Builder()->fromParameters(%s);
    PHPUnit\Event\Facade::instance()->seal();
    PHP_WRAP, var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 5)), return: true), var_export($added, return: true))]);
    $php->run();

    expect($php->getErrorOutput())->toBe('')
        ->and($php->getOutput())->toBe('');
});
