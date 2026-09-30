<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DOMDocument;

use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;

/** An XML report Infection or PHPUnit wrote, read without the network and without libxml's own warnings. */
final readonly class XmlFile
{
    private const int QUIET = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;

    /** The file's document, or the refusal given where the file is not there or not XML. */
    public static function read(string $file, CannotJudge $unreadable): DOMDocument|CannotJudge
    {
        $document = new DOMDocument();

        return is_file($file) && $document->load($file, self::QUIET) ? $document : $unreadable;
    }
}
