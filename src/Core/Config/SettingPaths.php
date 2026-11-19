<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function preg_quote;
use function preg_split;

use const PREG_SPLIT_DELIM_CAPTURE;

use function sprintf;

/**
 * The paths some of a set of settings is under: the top, and each setting's
 * path before every step down it, so `trees[].path` is under `trees` and
 * `trees[]`, and `run.failFast` is not under `run.fail`.
 *
 * @internal
 */
final readonly class SettingPaths
{
    /** The step from a setting's path into a member of its object: `floors.trees`. */
    private const string MEMBER = '.';

    /** The step from a setting's path into each entry of its list: `trees[].path`. */
    private const string EACH = '[]';

    /** @param array<string, true> $above every path some setting is under */
    private function __construct(private array $above)
    {
    }

    /** The paths these settings, each by its path, are under. */
    public static function of(string ...$settings): self
    {
        $above = [];

        foreach ($settings as $setting) {
            $above += self::above($setting);
        }

        return new self($above);
    }

    /** The path of a member of the object at a path, the top's members by their key alone. */
    public static function member(string $path, string $key): string
    {
        return $path === '' ? $key : sprintf('%s%s%s', $path, self::MEMBER, $key);
    }

    /** The path of each entry of the list at a path. */
    public static function each(string $path): string
    {
        return sprintf('%s%s', $path, self::EACH);
    }

    /** Whether some of the settings is under this path, as every one is under the top. */
    public function under(string $path): bool
    {
        return array_key_exists($path, $this->above);
    }

    /**
     * The paths one setting is under: the top, and its path before each step down it.
     *
     * @return array<string, true>
     */
    private static function above(string $setting): array
    {
        $step = sprintf('/(%s|%s)/', preg_quote(self::MEMBER, '/'), preg_quote(self::EACH, '/'));
        $pieces = preg_split($step, $setting, flags: PREG_SPLIT_DELIM_CAPTURE);
        $above = ['' => true];
        $before = '';

        foreach ($pieces === false ? [] : $pieces as $index => $piece) {
            if ($index % 2 === 1) {
                $above[$before] = true;
            }

            $before = sprintf('%s%s', $before, $piece);
        }

        return $above;
    }
}
