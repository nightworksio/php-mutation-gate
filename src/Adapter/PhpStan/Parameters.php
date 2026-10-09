<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * The configuration PHPStan runs with, read from what `dump-parameters`
 * prints (ADR-0020, decision 14): its parameters less those that differ
 * between machines and runs, and every file they name that PHPStan reads
 * besides the code.
 */
final readonly class Parameters
{
    /** The members of PHPStan's resolved parameters that differ between machines and runs, not what it judges. */
    private const array MACHINE = ['env', 'tmpDir', 'sysGetTempDir', 'resultCachePath', 'pro'];

    /**
     * The members of PHPStan's resolved parameters that name what it reads
     * besides the code it analyses: every config file, its includes and a
     * baseline among them, and the bootstrap, stub and scanned files.
     */
    private const array REFERENCES = ['allConfigFiles', 'bootstrapFiles', 'stubFiles', 'scanFiles', 'scanDirectories'];

    /** PHPStan's resolved parameters, as every machine writes them, with the files they name. */
    public static function settings(string $dumped, Root $root): AnalyserSettings|CannotJudge
    {
        $parameters = Node::decode($dumped, Scope::PARAMETERS);
        $settings = AnalyserSettings::resolved($dumped, $root->value(), ...self::MACHINE);
        $named = [];

        foreach (self::REFERENCES as $member) {
            foreach (Lenient::items($parameters->field($member)) as $file) {
                $named[] = Lenient::text($file);
            }
        }

        return $settings instanceof AnalyserSettings
            ? $settings->referencing(AnalyserSettings::filesNamed($root->value(), ...$named))
            : $settings;
    }
}
