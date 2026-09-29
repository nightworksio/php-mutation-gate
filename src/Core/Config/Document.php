<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function json_last_error_msg;
use function json_validate;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * A config as every format reads it: the untyped tree of maps, lists and
 * scalars, held as JSON text, before one validator turns it into settings.
 */
final readonly class Document
{
    private function __construct(private string $json)
    {
    }

    public static function ofJson(string $json): self|CannotJudge
    {
        if (! json_validate($json)) {
            return CannotJudge::because(sprintf(
                'A config was read into text that is not JSON: %s.',
                json_last_error_msg(),
            ));
        }

        return new self($json);
    }

    public function json(): string
    {
        return $this->json;
    }
}
