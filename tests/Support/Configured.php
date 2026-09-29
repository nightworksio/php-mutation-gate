<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Config\Document;

/** Config documents a test writes as JSON. */
final class Configured
{
    /** The document this JSON is; a test that writes JSON wrongly gets an empty one, and its expectation fails. */
    public static function document(string $json): Document
    {
        $document = Document::ofJson($json);

        return $document instanceof Document ? $document : self::document('{}');
    }
}
