<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Boot;
use NightWorksIO\MutationGate\Tests\Support\Declared;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A bootstrap that registers an autoloader for a class of its own, then
 * loads that class on its fourth line, and a file it requires itself.
 *
 * @return array{string, string, string} the bootstrap, the class's file, and the file it requires
 */
function bootBootstrap(string $class): array
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'Probe.php', sprintf("<?php\n\nfinal class %s {}\n", $class));
    Scratch::write($root, 'required.php', "<?php\n");
    Scratch::write($root, 'bootstrap.php', sprintf(
        "<?php\nspl_autoload_register(static fn(string \$c) => \$c === '%s' ? require __DIR__ . '/Probe.php' : null);\n"
        . "require __DIR__ . '/required.php';\nclass_exists('%s');\n",
        $class,
        $class,
    ));

    return [sprintf('%s/bootstrap.php', $root), sprintf('%s/Probe.php', $root), sprintf('%s/required.php', $root)];
}

it('names the line of the bootstrap that autoloaded a file, the bootstrap for a file it loaded otherwise, and the autoloader for one loaded before', function (): void {
    [$bootstrap, $class, $required] = bootBootstrap(Declared::name('BootProbe'));
    $boot = Boot::begun(Tree::at('vendor/autoload.php'));

    require $bootstrap;
    $boot = $boot->through($bootstrap)->done();

    expect($boot->whereLoaded($class))->toBe(sprintf('%s:4', $bootstrap))
        ->and($boot->whereLoaded($required))->toBe($bootstrap)
        ->and($boot->whereLoaded(Tree::at('vendor/autoload.php')))->toBe(Tree::at('vendor/autoload.php'))
        ->and($boot->ran())->toBe($bootstrap)
        ->and(Boot::begun(Tree::at('vendor/autoload.php'))->done()->ran())->toBe(Tree::at('vendor/autoload.php'));
});

it('names the autoloader for a file the bootstrap never loaded where there is no bootstrap', function (): void {
    $boot = Boot::begun(Tree::at('vendor/autoload.php'))->done();

    expect($boot->whereLoaded('/nowhere/at/all.php'))->toBe(Tree::at('vendor/autoload.php'));
});

it('names the bootstrap for a file autoloaded before the bootstrap ran, as when PHPUnit read its config', function (): void {
    [$bootstrap] = bootBootstrap(Declared::name('BootProbe'));
    $early = Declared::name('BootEarly');
    $file = sprintf('%s/Early.php', dirname($bootstrap));
    file_put_contents($file, sprintf("<?php\n\nfinal class %s {}\n", $early));
    $loader = static function (string $class) use ($early, $file): void {
        if ($class === $early) {
            require $file;
        }
    };
    spl_autoload_register($loader);
    $boot = Boot::begun(Tree::at('vendor/autoload.php'));

    class_exists($early);
    require $bootstrap;
    $boot = $boot->through($bootstrap)->done();
    spl_autoload_unregister($loader);

    expect($boot->whereLoaded($file))->toBe($bootstrap);
});

it('says the boot started PHPUnit\'s events where it first loaded their class, and not where it was loaded before', function (): void {
    $events = Declared::name('BootEvents');
    $boot = Boot::begun(Tree::at('vendor/autoload.php'), $events);
    $before = $boot->startedEvents();
    Declared::class($events);

    expect([$before, $boot->done()->startedEvents(), Boot::begun(Tree::at('vendor/autoload.php'), $events)->done()->startedEvents()])
        ->toBe([false, true, false])
        ->and(Boot::begun(Tree::at('vendor/autoload.php'))->done()->startedEvents())->toBeFalse();
});

it('takes its spy off the autoloaders once done, so no child pays for it', function (): void {
    $before = spl_autoload_functions();
    $boot = Boot::begun(Tree::at('vendor/autoload.php'));
    $watching = spl_autoload_functions();
    $boot->done();

    expect([count($watching), spl_autoload_functions()])->toBe([count($before) + 1, $before]);
});
