<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function json_decode;
use function json_encode;

use JsonSchema\Validator;

use function sprintf;

/** What a JSON Schema says is wrong with a document; nothing where it validates. */
final class Schema
{
    /** @return list<string> each error, with where it is */
    public static function errors(string $json, string $schema): array
    {
        $document = json_decode($json, associative: false);
        $validator = new Validator();
        $validator->validate($document, json_decode(sprintf('{"$ref": "file://%s"}', $schema), associative: false));
        $errors = [];

        foreach ($validator->getErrors() as $error) {
            $errors[] = (string) json_encode($error);
        }

        return $errors;
    }

    /** A file under the repository, by its path from the root. */
    public static function at(string $path): string
    {
        return sprintf('%s/%s', dirname(__DIR__, 2), $path);
    }
}
