<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function dirname;
use function file_put_contents;
use function getenv;
use function getmypid;
use function implode;
use function ini_get;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ChildProcesses;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\IniPath;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

use function putenv;
use function rmdir;
use function sprintf;
use function touch;
use function unlink;
use function var_export;

/**
 * The PHP processes a mutant's tests start, served the mutant as the test's
 * own process is (ADR-0150): an ini file every PHP process of the run scans
 * prepends a script that has the gate's wrapper serve the mutant in each
 * process a mutant's own run, a stream's child or a fresh run started. Those
 * serve their mutant as they do without it: the script serves where the
 * process that put the mutant in place told the processes it starts of it,
 * and where the gate named the mutant to the process that started it, as it
 * names it to a fresh run, whose own process prepends the override instead.
 * A file the PHP that runs the gate prepends is prepended after it. It is
 * beside the run's results, one for each process of the gate, and removed
 * when the run is done.
 *
 * @internal
 */
final readonly class ServedChildren
{
    /** What a process that put a mutant in place tells the processes it starts, as JSON. */
    public const string TOLD = 'MUTANT_SERVED_TO_CHILDREN';

    /** The file each PHP process started under it marks that it ran, where a split stream's test asks. */
    public const string SPAWNED = 'MUTATION_GATE_SPAWNED';

    /** The variables a mutant's run is told its mutant in, in the order a process tells the processes it starts. */
    private const array TOLD_FROM = [
        Recorder::MUTANT,
        Recorder::MUTATED,
        GateVariable::Schema->value,
        GateVariable::SchemaOn->value,
    ];

    /** What stands for each part of TOLD_FROM a process was not told, read where no autoloader loads its classes. */
    private const array UNTOLD = ['', '', '', ''];

    /** The directory of the run's ini file and script, beside its results, by the gate's process. */
    private const string DIRECTORY = 'children/%d';

    private const string INI = 'children.ini';

    private const string SCRIPT = 'children.php';

    private const string PREPEND = "auto_prepend_file=%s\n";

    private const string REQUIRING = "require_once %s;\n";

    /** The script: what it loads, then a closure that serves, leaving no variable behind, then what PHP prepends. */
    private const string SCRIPTED = <<<'SCRIPT'
        <?php

        declare(strict_types=1);

        %1$s%2$s\%3$s::marked();
        (static function (): void {
            [$original, $served] = \%3$s::served(%4$s, %5$s);
        %6$s})();
        %7$s
        SCRIPT;

    private const string UNWRITTEN
        = 'The script that serves a mutant to the processes its tests start cannot be written to %s.';

    private const string UNPREPENDABLE
        = 'PHP cannot prepend %s: an ini file reads a ", a ${ or a \\ in a path as ini syntax.';

    private function __construct(private string|NotGiven $directory)
    {
    }

    /**
     * The script written beside a run's results, where the run is patched,
     * as the plugin tells the processes it starts of its mutant, the project
     * places mutants and `pest.childProcesses` serves; none otherwise; or why
     * it cannot be written. Under the gate's PHAR, the script loads the
     * archive's autoloader first.
     */
    public static function beside(
        string $results,
        Patching $patching,
        MutantPlacement|NotGiven $placement,
        OwnArchive|NotGiven $archive,
    ): self|CannotJudge {
        $serves = $patching->isOn() && $patching->steps()->childProcesses() === ChildProcesses::Serve;

        if (! $serves || ! $placement instanceof MutantPlacement) {
            return new self(NotGiven::value());
        }

        $directory = self::directoryBeside($results);
        $script = sprintf('%s/%s', $directory, self::SCRIPT);

        if (! IniPath::isPlain($script)) {
            return CannotJudge::because(sprintf(self::UNPREPENDABLE, $script));
        }

        $written = (is_dir($directory) || mkdir($directory, recursive: true))
            && file_put_contents($script, self::script($directory, $placement, $archive)) !== false
            && file_put_contents(sprintf('%s/%s', $directory, self::INI), sprintf(self::PREPEND, $script)) !== false;

        return $written ? new self($directory) : CannotJudge::because(sprintf(self::UNWRITTEN, $directory));
    }

    /** The directory this process of the gate writes a run's script to, by the run's results file. */
    public static function directoryBeside(string $results): string
    {
        return sprintf('%s/%s', dirname($results), sprintf(self::DIRECTORY, getmypid()));
    }

    /** A command whose PHP processes scan the script's ini file too, after those it scans already. */
    public function onto(Command $command): Command
    {
        if ($this->directory instanceof NotGiven) {
            return $command;
        }

        $told = $command->environment();
        $inherited = array_key_exists(MemoryCap::SCAN_DIR, $told)
            ? $told[MemoryCap::SCAN_DIR]
            : getenv(MemoryCap::SCAN_DIR);

        return $command->with([MemoryCap::SCAN_DIR => MemoryCap::scanning($inherited, $this->directory)]);
    }

    /** Removes the script's directory once the run that scans it is done; a run that serves no child has none. */
    public function remove(): void
    {
        if ($this->directory instanceof NotGiven || ! is_dir($this->directory)) {
            return;
        }

        foreach ([self::SCRIPT, self::INI, Guard::SERVED] as $file) {
            $path = sprintf('%s/%s', $this->directory, $file);

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    /**
     * Tells the processes this one starts to serve the mutant this process
     * serves: the original, its mutated copy, and the schema and switch it
     * serves in the copy's place, where it serves one.
     */
    public static function tell(string $original, string $copy, string $schema, string $on): void
    {
        $told = json_encode([$original, $copy, $schema, $on]);

        if ($original !== '' && $copy !== '' && is_string($told)) {
            putenv(sprintf('%s=%s', self::TOLD, $told));
        }
    }

    /**
     * Tells the processes this one starts to serve the mutant these
     * variables put in place, as a stream's child is told its mutant.
     *
     * @param array<string, string> $variables
     */
    public static function tellOf(array $variables): void
    {
        [$original, $copy, $schema, $on] = array_map(
            static fn(string $name): string => array_key_exists($name, $variables) ? $variables[$name] : '',
            self::TOLD_FROM,
        );

        self::tell($original, $copy, $schema, $on);
    }

    /** Marks that this process ran, in the file the process that started it names; nothing where it names none. */
    public static function marked(): void
    {
        $marking = getenv(self::SPAWNED);

        if (is_string($marking) && $marking !== '') {
            touch($marking);
        }
    }

    /**
     * What the script serves in this process: the original and the file in
     * its place that the process that started it was told of, its schema's
     * switch put in force, or else what the gate names in these variables,
     * as it names it to a fresh run; nothing named where no mutant's run
     * started the process.
     *
     * @return array{string, string}
     */
    public static function served(string $original, string $mutated): array
    {
        $told = json_decode((string) getenv(self::TOLD), associative: true);
        $parts = is_array($told) ? array_values(array_filter($told, is_string(...))) : [];
        [$file, $copy, $schema, $on] = [...$parts, ...self::UNTOLD];

        if ($file === '' || $copy === '') {
            return [(string) getenv($original), (string) getenv($mutated)];
        }

        $served = SchemaSwitch::inForce($file, $copy, $schema, $on);

        return [$file, $served === '' ? $copy : $served];
    }

    /** The script, loading what it serves with by path, then any file the PHP that runs the gate prepends. */
    private static function script(string $directory, MutantPlacement $placement, OwnArchive|NotGiven $archive): string
    {
        $loading = $archive instanceof OwnArchive ? $archive->loadingWithAutoloader() : '';
        $required = array_map(
            static fn(string $file): string => sprintf(self::REQUIRING, var_export($file, return: true)),
            [ServedClasses::FILE, SchemaSwitch::FILE, __FILE__],
        );
        $prepended = (string) ini_get('auto_prepend_file');

        return sprintf(
            self::SCRIPTED,
            $loading,
            implode('', $required),
            self::class,
            var_export(Variable::Mutant->value, return: true),
            var_export(Variable::Mutated->value, return: true),
            $placement->serving('$original', '$served', sprintf('%s/%s', $directory, Guard::SERVED)),
            $prepended === '' ? '' : sprintf('require %s;', var_export($prepended, return: true)),
        );
    }
}
