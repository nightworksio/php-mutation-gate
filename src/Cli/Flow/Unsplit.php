<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * What a shard warns of where its settings split its streams and this PHP
 * cannot fork, so each mutant ran on its own (ADR-0117).
 *
 * @internal
 */
final readonly class Unsplit
{
    private const string SAID
        = 'Shard %d ran each mutant on its own: splitStream.enabled needs pcntl and posix, which this PHP lacks.';

    public static function warnings(Settings $settings, ShardId $id, bool $forks = true): Warnings
    {
        $splits = $settings->effective()->pest()->splits($settings->runner()->choice());

        return $splits && ! ($forks && Forking::isAvailable())
            ? Warnings::of(Warning::that(sprintf(self::SAID, $id->number())))
            : Warnings::none();
    }
}
