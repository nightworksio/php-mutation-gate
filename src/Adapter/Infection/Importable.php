<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Choices;
use NightWorksIO\MutationGate\Adapter\Infection\Import\ClassFiles;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Floor;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Ignores;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Logs;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Mapped;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Remaining;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Timeouts;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Trees;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Import\Import;

/**
 * A project's Infection config, as adopting the gate carries it over
 * (ADR-0016): whether it sets a floor of its own, `minMsi` or
 * `minCoveredMsi`, and ignores mutants by Infection's own rules, as `doctor`
 * asks; and the gate's config it seeds, with what became of each of its
 * keys, as `init --from` writes it. A config the gate cannot run Infection
 * over, such as one for another test framework, is not imported at all.
 */
final readonly class Importable
{
    private function __construct(private string $file, private Node $settings, private OwnConfig $own)
    {
    }

    /** The config in a file's text, or why it is not one Infection could read. */
    public static function in(string $file, string $text): InfectionConfig|CannotJudge
    {
        $settings = RelaxedJson::decode($file, $text);

        if ($settings instanceof CannotJudge) {
            return $settings;
        }

        return InfectionConfig::in(
            $file,
            minMsi: $settings->field(Mapped::MinMsi->value)->isPresent()
                || $settings->field(Mapped::MinCoveredMsi->value)->isPresent(),
            ignores: MutatorSettings::of($settings->field('mutators'))->patterns() !== [],
        );
    }

    /** The config in a file's text, or why the gate cannot take over from it. */
    public static function read(string $file, string $text): self|CannotJudge
    {
        $settings = RelaxedJson::decode($file, $text);

        if ($settings instanceof CannotJudge) {
            return $settings;
        }

        $own = OwnConfig::read($file, $text);

        return $own instanceof OwnConfig ? new self($file, $settings, $own) : $own;
    }

    /** The name of the file the config was read from. */
    public function file(): string
    {
        return $this->file;
    }

    /** The directories `source.directories` names, from the project's root. */
    public function directories(): Paths
    {
        $directories = [];

        foreach (Lenient::items($this->settings->field(Mapped::Source->value)->field('directories')) as $item) {
            $directory = Lenient::text($item);
            $directories = $directory === '' ? $directories : [...$directories, Path::of($directory)];
        }

        return Paths::of(...$directories);
    }

    /**
     * The gate's config this one seeds, over the trees zero-config found, and
     * what became of each key; an imported ignore expires 90 days from now.
     *
     * @param Listed<DeclaredTree>|Absent $found
     */
    public function imported(Project $project, Listed|Absent $found, DateTimeImmutable $now): Import
    {
        return Import::of(Layer::of(Setup::of(runner: Choices::runner())))
            ->and(Trees::of($this->settings, $project, $found))
            ->and(Floor::imported($this->settings))
            ->and(Timeouts::of($this->settings))
            ->and(Ignores::of($this->own->mutators(), $this->file, ClassFiles::in($project), $now))
            ->and(Logs::of($this->settings))
            ->and(Remaining::of($this->settings));
    }
}
