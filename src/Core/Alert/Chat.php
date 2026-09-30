<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use function addcslashes;
use function sprintf;
use function str_replace;
use function strtr;

/**
 * A chat an alert is written for, and how it writes text and code so that
 * nothing the project wrote becomes markup, a link or a mention there.
 */
enum Chat
{
    case Slack;
    case Discord;

    /** Characters Slack reads as markup in any text, as its escapes. */
    private const array SLACK = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];

    /** Characters Discord reads as Markdown, each escaped with a backslash. */
    private const string DISCORD = '\\*_~`|>[]()#-';

    public function text(string $text): string
    {
        return match ($this) {
            self::Slack => strtr($text, self::SLACK),
            self::Discord => addcslashes($text, self::DISCORD),
        };
    }

    /** Text as inline code, which can hold no backtick. */
    public function code(string $text): string
    {
        $code = str_replace('`', "'", $text);

        return match ($this) {
            self::Slack => sprintf('`%s`', strtr($code, self::SLACK)),
            self::Discord => sprintf('`%s`', $code),
        };
    }

    /** A heading over a group of lines. */
    public function heading(string $heading): string
    {
        return match ($this) {
            self::Slack => sprintf('*%s*', $heading),
            self::Discord => sprintf('**%s**', $heading),
        };
    }
}
