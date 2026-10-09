<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Adapter\Infection\Import\Choices;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Cli\Init\FoundMarkers;
use NightWorksIO\MutationGate\Cli\Init\InitAnswers;
use NightWorksIO\MutationGate\Cli\Init\InitQuestions;
use NightWorksIO\MutationGate\Cli\Init\NextSteps;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Ignores;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Component\Console\Input\InputInterface;

/**
 * One `init`: where no config is here, the questions detection leaves open,
 * asked in their order; then the config, the CI definition, the editor's
 * files and the hooks, each made where it is asked for and not there; then
 * what to run next (ADR-0017, decision 1). With a config here, it makes only
 * what the command line asks for, and asks nothing.
 */
final readonly class InitRun
{
    private const string KEPT = '%s is already here, so init makes only what --ci, --editor and --hook ask for.';

    private const string REFUSED = '%s is already here, and init writes a config only where there is none.';

    private const string LINES = "%s\n%s";

    public function __construct(
        private InputInterface $input,
        private Setting $setting,
        private Additions $additions,
        private InitQuestions|NotGiven $questions,
    ) {
    }

    /** What was made, and what to do next, said; or why not all of it could be made. */
    public function said(InfectionFile|NotGiven $from): string|Invalid|CannotJudge
    {
        $format = $this->input->getOption('format');
        $given = CommandLine::from($this->input);
        $destination = Destination::of($this->setting->project, $given->config, is_string($format) ? $format : '');

        return $destination instanceof CannotJudge ? $destination : $this->into($destination, $given, $from);
    }

    /** What is made into this destination, from what the command line and the first questions settle. */
    private function into(
        Destination $destination,
        CommandLine $given,
        InfectionFile|NotGiven $from,
    ): string|Invalid|CannotJudge {
        $next = NextSteps::before($this->setting->project);
        $guided = $this->questions instanceof InitQuestions && $destination->existing() instanceof NoConfigFile
            ? $this->questions
            : NotGiven::value();
        $answers = $guided instanceof InitQuestions
            ? $guided->first($given, $this->setting->effective->choosesRunner($given), $this->additions, $from)
            : InitAnswers::of($given, $from, NotGiven::value());
        $file = $answers->from instanceof InfectionFile
            ? $answers->from->in($this->setting->project)
            : NotGiven::value();

        if ($file instanceof CannotJudge) {
            return $file;
        }

        $made = $this->from($destination, $guided, $answers, $file);

        return is_string($made) && $guided instanceof InitQuestions ? $this->closed($made, $guided, $next) : $made;
    }

    /** What is made, with the Infection config to import, after the questions the settings settle. */
    private function from(
        Destination $destination,
        InitQuestions|NotGiven $guided,
        InitAnswers $answers,
        Path|NotGiven $file,
    ): string|Invalid|CannotJudge {
        $given = $file instanceof Path ? $answers->given->choosing(Choices::runner()->use()->value()) : $answers->given;
        $additions = $this->additions->answered($answers->ci, NotGiven::value());
        $settings = $this->settings($given, $destination->existing(), $file, $additions);
        $native = $guided instanceof InitQuestions && $settings instanceof Settings
            ? $guided->native($this->input, FoundMarkers::count($this->setting->extensions, $settings))
            : NotGiven::value();
        $hook = $guided instanceof InitQuestions ? $guided->hook($this->additions) : NotGiven::value();

        return $native instanceof CannotJudge
            ? $native
            : $this->made($settings, $destination, $file, $additions->answered($answers->ci, $hook), $native);
    }

    /** What was made, then how to add `composer mutate` where a person asked, and what to run next. */
    private function closed(string $made, InitQuestions $guided, NextSteps $next): string
    {
        $said = $guided->plugin() ? sprintf(self::LINES, $made, NextSteps::plugin()) : $made;

        return $this->additions->output() === Output::Written ? sprintf(self::LINES, $said, $next->said()) : $said;
    }

    /**
     * The config, where none is here, the CI definition, where it is asked for, the editor's files, where
     * `--editor` names one, and the hooks, said; or why they were not all made. A CI definition that cannot be
     * made, such as one whose file is already here, stops them all before anything is written; a file that then
     * cannot be written is said after what was.
     */
    private function made(
        Settings|Invalid|CannotJudge $settings,
        Destination $destination,
        Path|NotGiven $from,
        Additions $additions,
        NativeMarkers|NotGiven $native,
    ): string|Invalid|CannotJudge {
        $setting = $this->setting;
        $ci = $additions->ci();
        $definition = CiDefinition::packaged($setting->project, $setting->extensions, $setting->gate);
        $existing = $destination->existing();
        $prepared = $ci instanceof CiRequest && $settings instanceof Settings
            ? $definition->prepared($ci, $settings, kept: $existing instanceof Path)
            : NotGiven::value();
        $config = match (true) {
            $prepared instanceof CannotJudge => $prepared,
            ! $settings instanceof Settings => $settings,
            $existing instanceof Path
                => sprintf(self::KEPT, $existing->relativeTo(Path::of($setting->project))->value()),
            default => ConfigWriting::written(
                $setting,
                $settings,
                $destination,
                $from,
                ($prepared instanceof PreparedCi ? $prepared->config() : Layer::none())
                    ->over($native instanceof NativeMarkers ? Layer::of(Ignores::of(native: $native)) : Layer::none()),
                $additions->output(),
            ),
        };

        if (! is_string($config) || ! $settings instanceof Settings) {
            return $config;
        }

        $made = $prepared instanceof PreparedCi ? $definition->made($prepared, $settings->ci()) : '';
        $editor = is_string($made) ? $additions->editorMade($setting->project) : '';

        return Said::joined(
            $config,
            $made,
            $editor,
            is_string($editor) ? $additions->hookMade($setting->project, $setting->gate) : '',
        );
    }

    /**
     * The settings `init` writes from: what zero-config finds where no config is here; the config here where
     * the command line asks for something beside it; and otherwise none, since `init` never replaces a config.
     */
    private function settings(
        CommandLine $given,
        Path|NoConfigFile|CannotJudge $existing,
        Path|NotGiven $from,
        Additions $additions,
    ): Settings|Invalid|CannotJudge {
        return match (true) {
            $existing instanceof NoConfigFile => $this->setting->effective->settings($given->withoutConfig()),
            $existing instanceof Path && $additions->any() && ! $from instanceof Path
                => $this->setting->effective->settings($given),
            default => $this->refused($existing),
        };
    }

    private function refused(Path|CannotJudge $existing): CannotJudge
    {
        return $existing instanceof CannotJudge
            ? $existing
            : CannotJudge::because(sprintf(self::REFUSED, $existing->value()));
    }
}
