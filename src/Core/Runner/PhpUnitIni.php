<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_last;
use function is_array;

use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\NotGiven;

use function simplexml_load_string;

use SimpleXMLElement;

use function sprintf;
use function trim;

/**
 * What a PHPUnit config, as text, sets with `<ini>` under `<php>`, which
 * PHPUnit sets as it starts: after PHP has read its ini files, and so over
 * them, the memory cap's included (ADR-0004, decision 9).
 */
final readonly class PhpUnitIni
{
    /** Every value the config's `<php>` sets a setting of this name to; PHPUnit sets each in turn, so the last wins. */
    private const string INI = '/phpunit/php/ini[@name="%s"]/@value';

    private const string MEMORY_LIMIT = 'memory_limit';

    /** The `memory_limit` the config sets, where it sets one PHP reads. */
    public static function memoryIn(string $config): MemoryCap|NotGiven
    {
        $value = self::lastIn($config, self::MEMORY_LIMIT);
        $limit = $value instanceof NotGiven ? $value : MemoryCap::parse(trim($value));

        return $limit instanceof MemoryCap ? $limit : NotGiven::value();
    }

    /** Where the config has PHP print errors: the last `display_errors` it sets, where it sets one. */
    public static function displayIn(string $config): ErrorDisplay|NotGiven
    {
        $value = self::lastIn($config, InertSetting::DisplayErrors->value);

        return $value instanceof NotGiven ? $value : ErrorDisplay::read($value);
    }

    /** The last value the config sets the setting of this name to; none where it sets none or is not XML. */
    private static function lastIn(string $config, string $name): string|NotGiven
    {
        $xml = $config === ''
            ? false
            : simplexml_load_string($config, options: Xml::QUIET);
        $values = $xml instanceof SimpleXMLElement ? $xml->xpath(sprintf(self::INI, $name)) : [];

        return is_array($values) && $values !== [] ? (string) array_last($values) : NotGiven::value();
    }
}
