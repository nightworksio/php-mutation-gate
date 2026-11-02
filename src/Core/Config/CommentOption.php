<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The option the comment reporters `gitlab-comment` and `bitbucket-comment` take under a `reports` entry's `with`,
 * naming the environment variable the token they write with is read from (ADR-0024).
 */
enum CommentOption: string
{
    case TokenEnv = 'tokenEnv';

    /** The variable these options name, or this reporter's own where they name none or name it as anything else. */
    public static function tokenEnv(Options $options, string $otherwise): string
    {
        $variable = $options->text(Key::of(self::TokenEnv->value));

        return $variable instanceof NotGiven || $variable instanceof Problem ? $otherwise : $variable;
    }
}
