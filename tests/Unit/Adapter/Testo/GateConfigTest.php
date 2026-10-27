<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Testo\GateConfig;
use NightWorksIO\MutationGate\Adapter\Testo\GatePlugin;
use NightWorksIO\MutationGate\Adapter\Testo\Launch;
use NightWorksIO\MutationGate\Adapter\Testo\NotATestoConfig;
use NightWorksIO\MutationGate\Adapter\Testo\Told;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\Plugin\ApplicationPlugins;
use Testo\Application\Config\SuiteConfig;
use Testo\Filter\FilterPlugin;

afterEach(function (): void {
    chdir(Tree::at('.'));
    Scratch::sweep();
});

/**
 * The classes of the plugins a config loads, in order.
 *
 * @return list<class-string>
 */
function testoPlugins(ApplicationConfig $config): array
{
    $classes = [];

    foreach ($config->plugins as $plugin) {
        $classes[] = $plugin::class;
    }

    return $classes;
}

it('adds the gate\'s plugin after the project\'s own, whether it lists them or hands Testo its collection', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tests/.keep', '');
    Scratch::write($root, 'src/.keep', '');
    $suites = [new SuiteConfig(name: 'unit', location: [sprintf('%s/tests', $root)])];
    $project = new ApplicationConfig(src: [sprintf('%s/src', $root)], suites: $suites, plugins: [new FilterPlugin()]);
    $listed = GateConfig::wrapping($project, Told::from([]));
    $collected = GateConfig::wrapping(new ApplicationConfig(suites: $suites, plugins: ApplicationPlugins::only(new FilterPlugin())), Told::from([]));

    expect(testoPlugins($listed))->toBe([FilterPlugin::class, GatePlugin::class])
        ->and(testoPlugins($collected))->toBe([FilterPlugin::class, GatePlugin::class])
        ->and($listed->suites)->toBe($suites)
        ->and($listed->src)->toBe($project->src);
});

it('loads the project\'s own testo.php, from the directory Testo runs in, or Testo\'s default where there is none', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'testo.php', <<<'PHP'
        <?php

        return new Testo\Application\Config\ApplicationConfig(
            suites: [new Testo\Application\Config\SuiteConfig(name: 'mine', location: ['spec'])],
        );
        PHP);
    Scratch::write($root, 'spec/.keep', '');
    $bare = Scratch::directory();
    Scratch::write($bare, 'tests/.keep', '');

    chdir($root);
    $own = GateConfig::ofLauncher(['launcher', 'autoload.php', '--mutation-gate-stop=after-the-first-failure']);
    chdir($bare);
    $default = GateConfig::ofLauncher(['launcher', 'autoload.php']);

    expect($own->suites[0]->name)->toBe('mine')
        ->and(testoPlugins($own))->toBe([GatePlugin::class])
        ->and($default->suites[0]->name)->toBe('default');
});

it('refuses a testo.php that returns no Testo config, as Testo does', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'testo.php', "<?php\n\nreturn [];\n");
    chdir($root);

    expect(static fn(): ApplicationConfig => GateConfig::ofLauncher([]))->toThrow(
        NotATestoConfig::class,
        sprintf('Configuration file %s/testo.php must return an instance of %s.', getcwd(), ApplicationConfig::class),
    );
});

it('starts Testo\'s run over the gate\'s config with Testo\'s own arguments, and none of the gate\'s', function (): void {
    $launch = Launch::of(['--mutation-gate-group=g', '--no-ansi', '--suite=unit']);
    $testo = $launch->testo();

    $input = $launch->input('/gate/bin/mutation-gate-testo');

    expect($input->getFirstArgument())->toBe('run')
        ->and($input->getParameterOption('--config'))->toBe(Tree::at('bin/mutation-gate-testo-config.php'))
        ->and($input->getParameterOption('--suite'))->toBe('unit')
        ->and($input->hasParameterOption('--no-ansi'))->toBeTrue()
        ->and($input->hasParameterOption('--mutation-gate-group'))->toBeFalse()
        ->and($testo->has('run'))->toBeTrue()
        ->and($testo->getName())->toBe('Testo')
        ->and($testo->isAutoExitEnabled())->toBeFalse();
});
