<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;

use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\waiting;

return static function (string $into, string $root): void {
    if (getcwd() === $root || ! is_dir($into) || ! function_exists('pcov\collect')) {
        return;
    }

    start();
    $written = static function () use ($into): void {
        $lines = collect(constant('pcov\inclusive'), waiting());
        clear();
        $json = json_encode($lines);

        if ($lines !== [] && is_string($json)) {
            file_put_contents(sprintf('%s/%d-%d.json', $into, getmypid(), hrtime(as_number: true)), $json);
        }
    };
    register_shutdown_function($written);
    $arguments = $_SERVER['argv'];
    $first = is_array($arguments) ? reset($arguments) : false;
    $runner = is_string($first) ? basename($first) : '';
    $runs = in_array($runner, ['pest', 'paratest', 'worker.php', 'phpunit-wrapper.php'], strict: true)
        || str_starts_with($runner, 'mutation-gate-pest');
    $autoload = sprintf('%s/vendor/autoload.php', getcwd());

    if ($runs && is_file($autoload)) {
        require_once $autoload;

        if (class_exists(OwnEnd::class)) {
            OwnEnd::first($written);
        }
    }
};
