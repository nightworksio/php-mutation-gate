<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_any;

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function preg_match;
use function sprintf;

/**
 * A Codeception suite's settings, as the gate reads where its tests run
 * the application (ADR-0027): a suite whose enabled modules drive it
 * through PhpBrowser or WebDriver, by name or through a module that depends
 * on one, or whose coverage is collected remotely, serves it from another
 * process, which a mutant the override serves in the test's process never
 * reaches.
 */
final readonly class CodeceptionSuiteSettings
{
    /** A module that drives the application in another process: PhpBrowser or WebDriver, by name or class. */
    private const string ELSEWHERE = '/(?:^|\\\\)(?:PhpBrowser|WebDriver)$/';

    /** Whether settings, as Codeception's config writes them, serve the application from another process. */
    public static function servesElsewhere(Node $settings): bool
    {
        return Lenient::boolean($settings->field('coverage')->field('remote'), otherwise: false)
            || array_any(
                Lenient::items($settings->field('modules')->field('enabled')),
                static fn(Node $module): bool => array_any(self::namedBy($module), self::drivesElsewhere(...)),
            );
    }

    /**
     * The modules an enabled module's entry names: itself, by name or as
     * the key of its settings, and those its settings say it depends on;
     * an entry of settings names no module by its text.
     *
     * @return list<string>
     */
    private static function namedBy(Node $module): array
    {
        $named = [Lenient::text($module)];

        foreach (Lenient::entries($module) as $name => $settings) {
            $named = [...$named, sprintf('%s', $name), Lenient::text($settings->field('depends'))];
        }

        return $named;
    }

    private static function drivesElsewhere(string $module): bool
    {
        return preg_match(self::ELSEWHERE, $module) === 1;
    }
}
