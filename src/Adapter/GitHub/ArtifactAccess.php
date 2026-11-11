<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use JsonException;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;

use function sprintf;
use function unlink;

/**
 * The artifact service as the gate's action hands it to a shard's gate
 * process (ADR-0134): the job's runtime token and the service's URL, in a
 * file outside the workspace that only its owner reads, named by a variable
 * of the gate's step. The gate reads it once, first, and removes it, before
 * it starts any process; no step's environment holds the token, and the gate
 * withholds every `ACTIONS_` and `MUTATION_GATE_` variable from its tests.
 *
 * @internal
 */
final readonly class ArtifactAccess
{
    /** The variable that names the handoff file. */
    public const string FILE = 'MUTATION_GATE_ARTIFACT_SERVICE';

    private const string TOKEN = 'token';

    private const string URL = 'url';

    private const string NO_FILE = 'The artifact service is not reachable from this step: %s names no file.';

    private const string NO_HANDOFF
        = 'The artifact service is not reachable from this step: %s holds no token and URL.';

    private function __construct(private string $token, private string $url)
    {
    }

    public static function of(string $token, string $url): self
    {
        return new self($token, $url);
    }

    /** The service the file the environment names holds, the file removed whatever it held; or why there is none. */
    public static function handedIn(Variables $environment): self|CannotJudge
    {
        $file = $environment->valueOf(self::FILE);

        if ($file === '') {
            return CannotJudge::because(sprintf(self::NO_FILE, self::FILE));
        }

        $text = is_file($file) ? file_get_contents($file) : false;

        if (is_file($file)) {
            unlink($file);
        }

        try {
            $decoded = is_string($text) ? json_decode($text, true, 4, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $decoded = null;
        }

        $token = is_array($decoded) && is_string($decoded[self::TOKEN] ?? null) ? $decoded[self::TOKEN] : '';
        $url = is_array($decoded) && is_string($decoded[self::URL] ?? null) ? $decoded[self::URL] : '';

        return $token === '' || $url === ''
            ? CannotJudge::because(sprintf(self::NO_HANDOFF, $file))
            : new self($token, $url);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function url(): string
    {
        return $this->url;
    }
}
