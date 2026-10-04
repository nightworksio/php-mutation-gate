<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

use function sprintf;

/**
 * The configuration Mago runs with, as `mago config` prints it in JSON,
 * merged from its config file, the environment and its defaults (ADR-0020,
 * decision 14), without the sections for its other commands, and with the
 * files it names besides the code: its config file, the analyser's
 * baseline, and the sources it includes and patches.
 */
final readonly class Configuration
{
    private const string UNRESOLVED = 'Mago could not say the configuration it runs with (%s).';

    /** The sections for Mago's other commands, which do not change what `analyze` reports. */
    private const array OTHER_COMMANDS = ['linter', 'formatter', 'guard'];

    /** The members of the `source` section that name files Mago reads besides the paths it analyses. */
    private const array SOURCES = ['includes', 'patches'];

    /** What `mago config` printed, under the root, with these config files; or why Mago could not say it. */
    public static function shown(
        ChildProcess|CannotJudge $shown,
        Root $root,
        string ...$configFiles,
    ): AnalyserSettings|CannotJudge {
        if ($shown instanceof CannotJudge || $shown->exit() !== 0) {
            return CannotJudge::because(sprintf(
                self::UNRESOLVED,
                $shown instanceof CannotJudge ? $shown->why() : $shown->said(),
            ));
        }

        $merged = Node::decode($shown->output());
        $settings = AnalyserSettings::resolved($shown->output(), $root->value(), ...self::OTHER_COMMANDS);
        $named = [...$configFiles, Lenient::text($merged->field('analyzer')->field('baseline'))];

        foreach (self::SOURCES as $sources) {
            foreach (Lenient::items($merged->field('source')->field($sources)) as $source) {
                $named[] = Lenient::text($source);
            }
        }

        return $settings instanceof AnalyserSettings
            ? $settings->referencing(AnalyserSettings::filesNamed($root->value(), ...$named))
            : $settings;
    }
}
