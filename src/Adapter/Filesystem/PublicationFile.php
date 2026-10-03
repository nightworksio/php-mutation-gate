<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function dirname;

use const FILE_APPEND;

use function file_put_contents;
use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Delivery;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Written;

/** A CI plan's publication, put where it says: printed, written to its file, or appended to it. */
final readonly class PublicationFile
{
    /** Where it went; or why it could not go there. */
    public static function written(Publication $publication): Written|CannotJudge
    {
        $to = $publication->to();
        $text = $publication->text();
        $directory = dirname($to);

        $wrote = match ($publication->delivery()) {
            Delivery::Printed => file_put_contents($to, $text),
            Delivery::Appended => file_put_contents($to, $text, FILE_APPEND),
            Delivery::Written => is_dir($directory) || mkdir($directory, recursive: true)
                ? file_put_contents($to, $text)
                : false,
        };

        return Written::attempted($to, $wrote);
    }
}
