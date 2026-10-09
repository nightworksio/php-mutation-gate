<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\JsonDocument;

use function sprintf;

/** A file's migration, by the format it is written in (ADR-0026, decision 3). */
final readonly class Migrating
{
    private const string NOT_AN_OBJECT = '%s cannot be migrated: %s';

    /** What a format that holds comments its writer drops is noted with. */
    private const string COMMENTS = '%s is written again from its migrated form, so its comments are not kept.';

    /** A JSON config, edited key by key, naming the current major's published schema. */
    public static function json(string $file, string $text, Migrations $migrations): Migrated|CannotJudge
    {
        $document = self::document($file, $text);

        if ($document instanceof CannotJudge) {
            return $document;
        }

        $after = PublishedSchema::current($migrations->applied($document));

        return Migrated::of($file, $text, $after->text(), $migrations->left($after));
    }

    /** A baseline, edited key by key. */
    public static function baseline(string $file, string $text, Migrations $migrations): Migrated|CannotJudge
    {
        $document = self::document($file, $text);

        if ($document instanceof CannotJudge) {
            return $document;
        }

        $after = $migrations->applied($document);

        return Migrated::of($file, $text, $after->text(), $migrations->left($after));
    }

    /**
     * A config in a format that reads into this JSON form and is written again
     * from it, dropping its comments, and only where a change applies.
     *
     * @param Closure(Json): string $written the file this format writes of a JSON form
     */
    public static function rewritten(
        string $file,
        string $text,
        Json $form,
        Migrations $migrations,
        Closure $written,
    ): Migrated|CannotJudge {
        $document = self::document($file, $form->pretty());

        if ($document instanceof CannotJudge) {
            return $document;
        }

        $after = $migrations->applied($document);
        $json = $after->text() === $document->text() ? $form : Json::parse($after->text());

        return $json instanceof Json
            ? Migrated::of($file, $text, $json === $form ? $text : $written($json), $migrations->left($after))
                ->noting(sprintf(self::COMMENTS, $file))
            : $json;
    }

    private static function document(string $file, string $text): JsonDocument|CannotJudge
    {
        $document = JsonDocument::parse($text);

        return $document instanceof CannotJudge
            ? CannotJudge::because(sprintf(self::NOT_AN_OBJECT, $file, $document->why()))
            : $document;
    }
}
