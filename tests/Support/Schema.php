<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function hash;
use function hash_file;
use function is_file;
use function json_decode;
use function json_encode;

use JsonSchema\Validator;

use function rename;

use RuntimeException;

use function sprintf;

use Symfony\Component\HttpClient\HttpClient;

use function sys_get_temp_dir;

/** What a JSON Schema says is wrong with a document; nothing where it validates. */
final class Schema
{
    /** @return list<string> each error, with where it is */
    public static function errors(string $json, string $schema): array
    {
        $document = json_decode($json, associative: false);
        $validator = new Validator();
        $validator->validate($document, json_decode((string) file_get_contents($schema), associative: false));
        $errors = [];

        foreach ($validator->getErrors() as $error) {
            $errors[] = (string) json_encode($error);
        }

        return $errors;
    }

    /**
     * A schema whose publisher states no licence that lets this repository carry it: fetched into the system's
     * temporary directory, under its digest, and held to that digest. A fetch that fails, or a schema that has
     * changed, fails the test that reads it.
     */
    public static function fetched(string $url, string $sha256): string
    {
        $file = sprintf('%s/mutation-gate-schema-%s.json', sys_get_temp_dir(), $sha256);

        if (is_file($file) && hash_file('sha256', $file) === $sha256) {
            return $file;
        }

        $text = HttpClient::create()->request('GET', $url, ['timeout' => 30])->getContent();

        if (hash('sha256', $text) !== $sha256) {
            throw new RuntimeException(sprintf(
                '%s no longer has the SHA-256 %s. Read what changed, then pin its new digest.',
                $url,
                $sha256,
            ));
        }

        $partial = sprintf('%s.%d', $file, getmypid());
        file_put_contents($partial, $text);
        rename($partial, $file);

        return $file;
    }

    /** A file under the repository, by its path from the root. */
    public static function at(string $path): string
    {
        return sprintf('%s/%s', dirname(__DIR__, 2), $path);
    }
}
