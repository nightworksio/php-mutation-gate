<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Format\JsonText;

use const SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;

use function sodium_bin2base64;
use function sprintf;

/** A runtime token as GitHub hands a job one for its artifact service: a JSON web token carrying these scopes. */
final readonly class RuntimeToken
{
    private function __construct()
    {
    }

    public static function scoped(string $scopes): string
    {
        $claims = sodium_bin2base64(JsonText::compact(['scp' => $scopes]), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return sprintf('header.%s.signature', $claims);
    }
}
