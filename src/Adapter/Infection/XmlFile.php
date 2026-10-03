<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DOMDocument;

use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\NotGiven;

/** An XML report Infection or PHPUnit wrote, read without the network and without libxml's own warnings. */
final readonly class XmlFile
{
    /** The file's document, or the refusal given where the file is not there or not XML. */
    public static function read(string $file, CannotJudge $unreadable): DOMDocument|CannotJudge
    {
        $document = self::document($file);

        return $document instanceof DOMDocument ? $document : $unreadable;
    }

    /** The file's document; none where the file is not there or not XML. */
    public static function document(string $file): DOMDocument|NotGiven
    {
        $document = new DOMDocument();

        return is_file($file) && $document->load($file, Xml::QUIET) ? $document : NotGiven::value();
    }
}
