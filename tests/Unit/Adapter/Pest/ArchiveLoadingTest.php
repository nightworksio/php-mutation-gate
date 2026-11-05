<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\ArchiveLoading;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GatePlugins;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Cli\PestPlugin;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project in this directory whose Pest lists these plugins, the project being a copy of the gate. */
function archivedPest(string $directory, string ...$plugins): Project
{
    Scratch::write($directory, 'vendor/pest-plugins.json', (string) json_encode($plugins));
    Scratch::write($directory, 'composer.json', (string) json_encode([
        'name' => ThisPackage::COMPOSER,
        'autoload' => ['psr-4' => ['NightWorksIO\\MutationGate\\' => 'src/']],
    ]));

    return Project::at($directory, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
}

$archive = static fn(): OwnArchive => OwnArchive::at('/opt/judge/mutation-gate-judge.phar');

it('prepends a script that loads the archive and names its plugin as each plugin of the gate\'s Pest lists', function () use ($archive): void {
    $project = archivedPest((string) realpath(Scratch::directory()), PestPlugin::class, 'Acme\\Plugin', 'NightWorksIO\\MutationGate\\Cli\\EarlierPlugin');
    $loading = ArchiveLoading::writtenFor($project, $archive(), GatePlugins::listedIn($project, new PackageDirectory()), PestPlugin::class);
    $script = sprintf('%s/archive/prepend.php', $project->workspace());
    $ini = sprintf('%s/archive/php/archive.ini', $project->workspace());

    expect($loading)->toBeInstanceOf(ArchiveLoading::class)
        ->and((string) file_get_contents($script))->toBe(sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                %srequire %s;

                $pest = static function (string $class): void {
                    $file = sprintf('%%s/%%s.php', %s, strtr(substr($class, 5), '\\', '/'));

                    if (str_starts_with($class, 'Pest\\') && is_file($file)) {
                        require $file;
                    }
                };
                spl_autoload_register($pest);
                \class_alias(%s, 'NightWorksIO\\MutationGate\\Cli\\PestPlugin');
                \class_alias(%s, 'NightWorksIO\\MutationGate\\Cli\\EarlierPlugin');

                spl_autoload_unregister($pest);

                PHP,
            $archive()->loading(),
            var_export($archive()->autoloader(), return: true),
            var_export(sprintf('%s/vendor/pestphp/pest/src', $project->root()), return: true),
            var_export(PestPlugin::class, return: true),
            var_export(PestPlugin::class, return: true),
        ))
        ->and((string) file_get_contents($ini))->toBe(sprintf("auto_prepend_file=\"%s\"\n", $script));
});

it('has a command\'s PHP processes scan the ini file\'s directory after those the command or the gate names', function () use ($archive): void {
    $project = archivedPest((string) realpath(Scratch::directory()), PestPlugin::class);
    $loading = ArchiveLoading::writtenFor($project, $archive(), GatePlugins::listedIn($project, new PackageDirectory()), PestPlugin::class);
    $directory = sprintf('%s/archive/php', $project->workspace());
    $capped = Command::of(PHP_BINARY, '-v')->with([MemoryCap::SCAN_DIR => '/cap']);
    $inherited = getenv(MemoryCap::SCAN_DIR);
    putenv(sprintf('%s=/inherited', MemoryCap::SCAN_DIR));

    try {
        $plain = $loading instanceof ArchiveLoading ? $loading->onto(Command::of(PHP_BINARY, '-v'))->environment()[MemoryCap::SCAN_DIR] : $loading;
    } finally {
        putenv(is_string($inherited) ? sprintf('%s=%s', MemoryCap::SCAN_DIR, $inherited) : MemoryCap::SCAN_DIR);
    }

    expect($loading instanceof ArchiveLoading ? $loading->onto($capped)->environment()[MemoryCap::SCAN_DIR] : $loading)
        ->toBe(sprintf('/cap%s%s', PATH_SEPARATOR, $directory))
        ->and($plain)->toBe(sprintf('/inherited%s%s', PATH_SEPARATOR, $directory));
});

it('is refused where the project\'s Pest lists no plugin of a copy of the gate', function () use ($archive): void {
    $project = archivedPest((string) realpath(Scratch::directory()), 'Acme\\Plugin');

    expect(ArchiveLoading::writtenFor($project, $archive(), GatePlugins::listedIn($project, new PackageDirectory()), PestPlugin::class))->toEqual(CannotJudge::because(
        'Pest finds the gate\'s plugin only by a name its list holds, and the project\'s lists no copy\'s: composer require --dev nightworksio/mutation-gate -W.',
    ));
});

it('is refused where PHP would read the script\'s path as ini syntax, or the ini file cannot be written', function () use ($archive): void {
    $quoted = archivedPest(sprintf('%s/a"b', (string) realpath(Scratch::directory())), PestPlugin::class);
    $blocked = archivedPest((string) realpath(Scratch::directory()), PestPlugin::class);
    mkdir(sprintf('%s/archive/php/archive.ini', $blocked->workspace()), recursive: true);

    expect(ArchiveLoading::writtenFor($quoted, $archive(), GatePlugins::listedIn($quoted, new PackageDirectory()), PestPlugin::class))->toEqual(CannotJudge::because(sprintf(
        'PHP cannot prepend %s/archive/prepend.php: an ini setting reads a ", a ${ or a \\\\ in it as ini syntax.',
        $quoted->workspace(),
    )))
        ->and(ArchiveLoading::writtenFor($blocked, $archive(), GatePlugins::listedIn($blocked, new PackageDirectory()), PestPlugin::class))->toBeInstanceOf(CannotJudge::class);
});
