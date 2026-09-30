<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function simplexml_load_string;

/** What an XML document holds where an XPath query points, as text. */
final class Xpath
{
    /** @return list<string> the text of every node the query finds; none for a document that is not XML */
    public static function of(string $xml, string $query): array
    {
        $document = simplexml_load_string($xml);
        $found = $document === false ? [] : $document->xpath($query);
        $texts = [];

        foreach ($found === false || $found === null ? [] : $found as $node) {
            $texts[] = (string) $node;
        }

        return $texts;
    }
}
