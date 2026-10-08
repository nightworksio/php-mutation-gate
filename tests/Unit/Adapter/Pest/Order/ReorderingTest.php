<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\Reordering;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** An order directory holding an order for the mutated copy at /tmp/mutations/abc. */
function reorderingDirectory(): string
{
    $order = Scratch::directory();
    Scratch::write(Seed::directoryOf($order, '/tmp/mutations/abc'), Seed::HISTORY, '{}');

    return $order;
}

it('runs a mutant\'s tests in the order written for it, dropping every option that would contradict it', function (): void {
    $order = reorderingDirectory();
    $arguments = [
        'vendor/bin/pest', '--cache-directory', '/v/.temp', '--no-tia', '--cache-directory=/elsewhere',
        '--order-by', 'random', '--order-by=size', '--record-test-run-history', '--do-not-record-test-run-history',
        '--cache-result', '--do-not-cache-result', '--bail', '--filter=MoneySpec',
    ];

    expect(Reordering::of($arguments, $order, '/tmp/mutations/abc'))->toBe([
        'vendor/bin/pest', '--no-tia', '--bail', '--filter=MoneySpec',
        sprintf('--cache-directory=%s/abc', $order),
        '--record-test-run-history',
        '--order-by=defects,duration-ascending',
    ]);
});

it('leaves the arguments as they are where no order was written, or this is no mutant\'s process', function (): void {
    $order = reorderingDirectory();
    $arguments = ['vendor/bin/pest', '--cache-directory', '/v/.temp', '--bail'];

    expect(Reordering::of($arguments, $order, '/tmp/mutations/other'))->toBe($arguments)
        ->and(Reordering::of($arguments, $order, mutated: false))->toBe($arguments)
        ->and(Reordering::of($arguments, $order, ''))->toBe($arguments)
        ->and(Reordering::of($arguments, directory: false, mutated: '/tmp/mutations/abc'))->toBe($arguments)
        ->and(Reordering::of($arguments, '', '/tmp/mutations/abc'))->toBe($arguments);
});

it('adds only options PHPUnit takes without a deprecation, a notice or a warning, any of which a project can fail its run on', function (): void {
    $order = reorderingDirectory();
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
