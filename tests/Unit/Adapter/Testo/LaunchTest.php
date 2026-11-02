<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Testo\GatePlugin;
use NightWorksIO\MutationGate\Adapter\Testo\Launch;
use NightWorksIO\MutationGate\Adapter\Testo\Told;
use NightWorksIO\MutationGate\Adapter\Testo\UnwrittenConfig;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Testo\Application\Config\ApplicationConfig;

afterEach(function (): void {
    chdir(Tree::at('.'));
    Scratch::sweep();
});

it('writes the gate\'s config for a run to a file of its own, which returns the project\'s with the gate\'s plugin', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/.keep', '');
    $arguments = ["--mutation-gate-group=it's", '--no-ansi'];
    chdir($root);

    $file = Launch::of($arguments)->written();
    $config = (static fn(string $written): mixed => require $written)($file);
    $plugins = $config instanceof ApplicationConfig ? [...$config->plugins] : [];
    Launch::cleared($file);

    expect(realpath(dirname($file)))->toBe(realpath(sys_get_temp_dir()))
        ->and(basename($file))->toStartWith('mutation-gate-testo-')
        ->and($plugins)->toEqual([new GatePlugin(Told::from($arguments))])
        ->and(is_file($file))->toBeFalse();
});

it('writes each run\'s config to a file apart, and clears one that is gone without a word', function (): void {
    $first = Launch::of([])->written();
    $second = Launch::of([])->written();
    Launch::cleared($first);
    Launch::cleared($first);

    expect($second)->not->toBe($first)
        ->and(is_file($second))->toBeTrue()
        ->and(is_file($first))->toBeFalse();

    Launch::cleared($second);
});

it('says where it could not write the gate\'s config', function (): void {
    expect(UnwrittenConfig::in('/tmp')->getMessage())->toBe('The gate cannot write its Testo config into /tmp.');
});
