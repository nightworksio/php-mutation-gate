<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use function array_key_exists;

use Closure;

use function is_file;
use function is_writable;

use NightWorksIO\MutationGate\Adapter\Infection\Patch as InfectionPatch;
use NightWorksIO\MutationGate\Adapter\Infection\UnsupportedRelease;
use NightWorksIO\MutationGate\Adapter\Pest\Patch as PestPatch;
use NightWorksIO\MutationGate\Cli\Command\IgnoredWorkspace;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Check\Driver;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Change;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Edit;
use NightWorksIO\MutationGate\Core\Doctor\Fix\IniText;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Lines;
use NightWorksIO\MutationGate\Core\Doctor\Fix\PhpUnitXml;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repair;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repairs;
use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\Doctor\GatePins;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * What `doctor --fix` does about each finding it has a repair for (ADR-0083):
 * opcache turned off and a coverage driver loaded in the runner PHP's
 * php.ini, which it must be able to write; the PHPUnit config's
 * `memory_limit` lowered to the cap, and its `<source>` including the
 * trees; `.gitignore` naming the workspace; Pest's and Infection's patches
 * applied; and each CI definition pinning the installed release. It names
 * and never runs what would install a package. It writes only the edits
 * it is handed, each from the text it was shown from.
 *
 * @internal
 */
final readonly class Fixing
{
    private const string NO_INI = 'The runner\'s PHP loads no php.ini: create one, then run doctor --fix again.';

    private const string UNWRITABLE = 'doctor --fix cannot write %s: make it writable, or make the change by hand.';

    private const string INSTALL = 'Install pcov with pecl install pcov, then run doctor --fix again.';

    private const string NO_CONFIG = 'The project has no PHPUnit config for doctor --fix to change.';

    private const string NO_TREES = 'The config names no tree for the PHPUnit config\'s <source> to include.';

    private const string OFF = '0';

    public function __construct(private string $project, private PackageFiles $packages)
    {
    }

    /**
     * Each file the repairs change, from the text it holds now.
     *
     * @return list<Edit>
     */
    public function edits(Repairs $repairs): array
    {
        $files = new FixedFiles($this->project);

        return $repairs->edits($files->read(...));
    }

    /** How many of these edits were written; or why one could not be. */
    public function write(Edit ...$edits): int|CannotJudge
    {
        return new FixedFiles($this->project)->write(...$edits);
    }

    /** The repair of each finding there is one for, in the order of the findings. */
    public function repairs(Findings $findings, Observations $observed): Repairs
    {
        $repairs = [];

        foreach ($findings as $finding) {
            $repair = $this->repair($finding, $observed);
            $repairs = $repair instanceof Repair ? [...$repairs, $repair] : $repairs;
        }

        return Repairs::of(...$repairs);
    }

    private function repair(Finding $finding, Observations $observed): Repair|NotGiven
    {
        $vendor = ComposerVendor::on($this->project);
        $packages = $this->packages;
        $repairs = [
            Slug::OpcacheOnTheCommandLine->value => fn(): Repair => $this->inIni(
                Slug::OpcacheOnTheCommandLine,
                $observed,
                static fn(string $ini): string => IniText::setting(
                    IniText::setting($ini, Opcache::CLI, self::OFF),
                    Opcache::FILE_CACHE,
                    '',
                ),
            ),
            Slug::NoCoverageDriver->value => fn(): Repair => $this->driver($observed),
            Slug::MemoryCapLifted->value => static fn(): Repair|NotGiven => self::memory($observed),
            Slug::CoverageEmpty->value => fn(): Repair => $this->sources($observed),
            Slug::WorkspaceNotIgnored->value => static fn(): Repair => Repair::changing(
                Slug::WorkspaceNotIgnored,
                Change::of(
                    GitIgnore::FILE,
                    static fn(string $text): string => Lines::appended($text, IgnoredWorkspace::line()),
                ),
            ),
            Slug::PestUnpatched->value => static fn(): Repair|NotGiven => self::planned(
                Slug::PestUnpatched,
                PestPatch::plannedIn($vendor, $packages),
            ),
            Slug::InfectionUnpatched->value => static fn(): Repair|NotGiven => self::planned(
                Slug::InfectionUnpatched,
                InfectionPatch::plannedIn($vendor, $packages),
            ),
            Slug::WorkflowPinStale->value => static fn(): Repair|NotGiven => self::repinned($observed),
        ];
        $slug = $finding->slug()->value;

        return array_key_exists($slug, $repairs) ? $repairs[$slug]() : NotGiven::value();
    }

    private function driver(Observations $observed): Repair
    {
        $php = $observed->php();

        return $php instanceof RunnerPhp && ! $php->offers(Driver::PCOV)
            ? Repair::refused(Slug::NoCoverageDriver, self::INSTALL)
            : $this->inIni(
                Slug::NoCoverageDriver,
                $observed,
                static fn(string $ini): string => IniText::extension($ini, Driver::PCOV),
            );
    }

    /** @param Closure(string): string $becomes */
    private function inIni(Slug $slug, Observations $observed, Closure $becomes): Repair
    {
        $php = $observed->php();
        $ini = $php instanceof RunnerPhp ? $php->loadedIni() : NotGiven::value();

        return match (true) {
            $ini instanceof NotGiven => Repair::refused($slug, self::NO_INI),
            ! is_writable($ini) => Repair::refused($slug, sprintf(self::UNWRITABLE, $ini)),
            default => Repair::changing($slug, Change::of($ini, $becomes)),
        };
    }

    private static function memory(Observations $observed): Repair|NotGiven
    {
        $settings = $observed->settings();
        $phpUnit = $observed->files()->runnerConfig()->phpUnitMemory();

        if (! $settings instanceof Settings || ! $phpUnit instanceof PhpUnitMemory) {
            return NotGiven::value();
        }

        $cap = $settings->runner()->memory()->written();

        $lowered = Change::of(
            $phpUnit->config()->value(),
            static fn(string $xml): string => PhpUnitXml::memoryLimit($xml, $cap),
        );

        return Repair::changing(Slug::MemoryCapLifted, $lowered);
    }

    private function sources(Observations $observed): Repair
    {
        $trees = $observed->trees();
        $directories = [];

        foreach ($trees instanceof Trees ? $trees : [] as $tree) {
            $directories[] = $tree->path()->value();
        }

        $config = $this->phpUnitConfig();

        return match (true) {
            $config instanceof NotGiven => Repair::refused(Slug::CoverageEmpty, self::NO_CONFIG),
            $directories === [] => Repair::refused(Slug::CoverageEmpty, self::NO_TREES),
            default => Repair::changing(
                Slug::CoverageEmpty,
                Change::of($config, static fn(string $xml): string => PhpUnitXml::sources($xml, $directories)),
            ),
        };
    }

    /** The first PHPUnit config the project holds, as PHPUnit looks for it. */
    private function phpUnitConfig(): string|NotGiven
    {
        foreach (PhpUnitConfig::candidatesIn(Path::root()) as $candidate) {
            if (is_file(sprintf('%s/%s', $this->project, $candidate->value()))) {
                return $candidate->value();
            }
        }

        return NotGiven::value();
    }

    /** @param array<string, string>|UnsupportedRelease|CannotJudge $planned each file a patch changes, by path */
    private static function planned(Slug $slug, array|UnsupportedRelease|CannotJudge $planned): Repair|NotGiven
    {
        if ($planned instanceof UnsupportedRelease) {
            return NotGiven::value();
        }

        if ($planned instanceof CannotJudge) {
            return Repair::refused($slug, $planned->why());
        }

        $changes = [];

        foreach ($planned as $file => $source) {
            $changes[] = Change::of($file, static fn(): string => $source);
        }

        return Repair::changing($slug, ...$changes);
    }

    private static function repinned(Observations $observed): Repair|NotGiven
    {
        $definitions = $observed->files()->runningTheGate();
        $run = $observed->run();

        if (! $definitions instanceof GateDefinitions || ! $run instanceof DoctorRun) {
            return NotGiven::value();
        }

        $pin = $run->pin();
        $changes = [];

        foreach ($definitions as $definition => $text) {
            $repinned = Change::of($definition, static fn(string $read): string => GatePins::repinned($read, $pin));
            $changes = GatePins::stale($text, $pin) === [] ? $changes : [...$changes, $repinned];
        }

        return Repair::changing(Slug::WorkflowPinStale, ...$changes);
    }
}
