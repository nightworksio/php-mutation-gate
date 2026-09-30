<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_last;
use function is_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

use function simplexml_load_string;

use SimpleXMLElement;

use function trim;

/**
 * What a PHPUnit config sets with `<ini>` under `<php>`, which PHPUnit sets
 * as it starts: after PHP has read its ini files, and so over them.
 */
final readonly class PhpUnitIni
{
    /** Every `memory_limit` the config sets; PHPUnit sets each in turn, so the last wins. */
    private const string MEMORY = '/phpunit/php/ini[@name="memory_limit"]/@value';

    /** The `memory_limit` a PHPUnit config, as text, sets, where it sets one PHP reads. */
    public static function memoryIn(string $config): MemoryCap|NotGiven
    {
        $xml = $config === ''
            ? false
            : simplexml_load_string($config, options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $values = $xml instanceof SimpleXMLElement ? $xml->xpath(self::MEMORY) : [];
        $limit = is_array($values) && $values !== []
            ? MemoryCap::parse(trim((string) array_last($values)))
            : NotGiven::value();

        return $limit instanceof MemoryCap ? $limit : NotGiven::value();
    }
}
