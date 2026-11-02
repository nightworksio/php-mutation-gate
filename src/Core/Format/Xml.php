<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use DOMDocument;

use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How the gate reads XML it did not write, such as a project's PHPUnit
 * config or the JUnit log a run wrote: it fetches nothing from the network
 * and prints no error or warning of libxml's, so a file that is not XML
 * reads as none.
 */
final readonly class Xml
{
    /** The libxml options every XML read takes. */
    public const int QUIET = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;

    /** The document a text holds; none where there is no text, or it is not XML. */
    public static function document(string|false $text): DOMDocument|NotGiven
    {
        $document = new DOMDocument();

        $read = $text !== false && $text !== '' && $document->loadXML($text, self::QUIET);

        return $read ? $document : NotGiven::value();
    }
}
