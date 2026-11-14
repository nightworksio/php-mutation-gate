<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function count;
use function dirname;
use function getenv;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\IniPath;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

use function sprintf;
use function var_export;

/**
 * What has every PHP process of a Pest run load the gate's archive first,
 * where the gate runs from one (ADR-0140): a script PHP prepends, which loads
 * the archive and its autoloader and gives the archive's own Pest plugin the
 * name of each plugin of a copy of the gate the project's Pest lists, so Pest
 * boots the archive's plugin in its place and never loads the copy's; and an
 * ini file that prepends the script, in a directory each process scans after
 * those it scans already. The plugin is named before Composer's autoloader,
 * which would find the copy's, is registered, so the contracts it implements
 * are read from Pest's own source in the project; no other code of the
 * project's is loaded. Pest finds a plugin only by the name its list holds,
 * so a project whose Pest lists no plugin of the gate's is refused.
 *
 * @internal
 */
final readonly class ArchiveLoading
{
    /** The script PHP prepends, among the adapter's files. */
    private const string SCRIPT = 'archive/prepend.php';

    /** The ini file that prepends it, in a directory of its own for `PHP_INI_SCAN_DIR` to name. */
    private const string INI = 'archive/php/archive.ini';

    /** Where Pest's own source is in the vendor directory, which its namespace's classes are read from. */
    private const string PEST_SOURCE = 'pestphp/pest/src';

    /** The script: the archive and its autoloader loaded, then the plugin named with Pest's contracts at hand. */
    private const string PREPENDED = <<<'PHP'
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
        %s
        spl_autoload_unregister($pest);

        PHP;

    /** The line that gives the archive's plugin a listed plugin's name. */
    private const string ALIAS = "\\class_alias(%s, %s);\n";

    private const string UNLISTED
        = 'Pest finds the gate\'s plugin only by a name its list holds, and the project\'s lists no copy\'s: %s.';

    /** What installs a copy of the gate whose plugin Pest lists. */
    private const string REQUIRE = 'composer require --dev nightworksio/mutation-gate -W';

    private const string UNPREPENDABLE
        = 'PHP cannot prepend %s: an ini setting reads a ", a ${ or a \\\\ in it as ini syntax.';

    private function __construct(private string $directory)
    {
    }

    /**
     * The script and its ini file, written in the gate's directory of a
     * project for an archive, giving the archive's plugin, this class, the
     * name of each of these plugins of a copy of the gate; or why Pest's
     * processes cannot be started with them.
     *
     * @param class-string $plugin
     */
    public static function writtenFor(
        Project $project,
        OwnArchive $archive,
        GatePlugins $listed,
        string $plugin,
    ): self|CannotJudge {
        $names = $listed->names();
        $text = sprintf(
            self::PREPENDED,
            $archive->loading(),
            var_export($archive->autoloader(), return: true),
            var_export($project->absolute($project->vendor()->child(Path::of(self::PEST_SOURCE))), return: true),
            self::aliases($plugin, ...$names),
        );
        $script = count($names) === 0
            ? CannotJudge::because(sprintf(self::UNLISTED, self::REQUIRE))
            : $project->written(self::SCRIPT, $text);

        return match (true) {
            $script instanceof CannotJudge => $script,
            ! IniPath::isPlain($script) => CannotJudge::because(sprintf(self::UNPREPENDABLE, $script)),
            default => self::prepending($project, $script),
        };
    }

    /** A command whose PHP processes scan the ini file's directory too, after those it names or they scan already. */
    public function onto(Command $command): Command
    {
        $environment = $command->environment();
        $scanned = array_key_exists(MemoryCap::SCAN_DIR, $environment)
            ? $environment[MemoryCap::SCAN_DIR]
            : getenv(MemoryCap::SCAN_DIR);

        return $command->with([MemoryCap::SCAN_DIR => MemoryCap::scanning($scanned, $this->directory)]);
    }

    /** The ini file that prepends the script, written, by its directory; or why it cannot be. */
    private static function prepending(Project $project, string $script): self|CannotJudge
    {
        $ini = $project->written(self::INI, sprintf("auto_prepend_file=\"%s\"\n", $script));

        return $ini instanceof CannotJudge ? $ini : new self(dirname($ini));
    }

    /** The lines that give the archive's plugin each of these names. */
    private static function aliases(string $plugin, string ...$names): string
    {
        $lines = [];

        foreach ($names as $name) {
            $lines[] = sprintf(
                self::ALIAS,
                var_export($plugin, return: true),
                var_export($name, return: true),
            );
        }

        return implode('', $lines);
    }
}
