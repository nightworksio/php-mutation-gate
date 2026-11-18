<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;

use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\waiting;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade;
use PHPUnit\Event\TestRunner\Finished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;
use PHPUnit\Event\UnknownSubscriberTypeException;

return static function (string $into, string $root): void {
    $naming = 'PROCESS_COVERAGE_TEST';
    $arguments = $_SERVER['argv'];
    $first = is_array($arguments) ? reset($arguments) : false;
    $runner = is_string($first) ? basename($first) : '';
    $runs = in_array($runner, ['pest', 'paratest', 'worker.php', 'phpunit-wrapper.php'], strict: true)
        || str_starts_with($runner, 'mutation-gate-pest');
    $fresh = $runner === 'fresh.php';
    $autoload = sprintf('%s/vendor/autoload.php', getcwd());

    if (! is_dir($into) || ! function_exists('pcov\collect') || ini_get('pcov.enabled') !== '1') {
        return;
    }

    if (getcwd() === $root && $runs) {
        $parallel = in_array('--parallel', $arguments, strict: true) || in_array('-p', $arguments, strict: true);

        if (! $parallel) {
            define('PROCESS_COVERAGE_NAMING', $naming);
        }

        return;
    }

    start();
    $written = static function () use ($into, $naming): void {
        $lines = collect(constant('pcov\inclusive'), waiting());
        clear();
        $test = getenv($naming);
        $json = json_encode(is_string($test) && $test !== '' ? ['test' => $test, 'lines' => $lines] : ['lines' => $lines]);

        if ($lines !== [] && is_string($json)) {
            file_put_contents(sprintf('%s/%d-%d.json', $into, getmypid(), hrtime(as_number: true)), $json);
        }
    };
    register_shutdown_function(static function () use ($written): void {
        register_shutdown_function($written);
    });

    if (($runs || $fresh) && is_file($autoload)) {
        require_once $autoload;

        if (class_exists(OwnEnd::class)) {
            OwnEnd::first($written);
        }

        if (! $runs || ! interface_exists(FinishedSubscriber::class)) {
            return;
        }

        try {
            Facade::instance()->registerSubscriber(new class implements FinishedSubscriber {
                /** Records again once the run's own coverage stopped recording at its last test, for what runs after it. */
                public function notify(Finished $event): void
                {
                    start();
                }
            });
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return;
        }
    }
};
