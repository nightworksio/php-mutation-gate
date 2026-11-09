<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function explode;
use function is_array;
use function json_decode;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The settings that affect results a config's `pr` and `main` context blocks
 * leave different, as a run in CI resolves them: a pull request's keys then
 * differ from the default branch's, and it can take none of its proofs of the
 * units it reaches (ADR-0071 item 5, ADR-0112). Each is named once.
 *
 * @internal
 */
final readonly class KeyedContexts
{
    /** @param list<string> $keys */
    private function __construct(private array $keys)
    {
    }

    /** The keyed settings a pull request and the default branch resolve differently from this layer. */
    public static function of(Layer $written): self
    {
        $pullRequest = self::decoded($written->in(ContextChain::inCi(NotGiven::value(), Context::PullRequest)));
        $main = self::decoded($written->in(ContextChain::inCi(NotGiven::value(), Context::Main)));
        $keys = [];

        foreach (Definition::effects() as $path => $effect) {
            if ($effect->isKeyed() && self::at($pullRequest, $path) !== self::at($main, $path)) {
                $keys[] = $path;
            }
        }

        return new self($keys);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->keys;
    }

    /** @return array<mixed> */
    private static function decoded(Layer $layer): array
    {
        $decoded = json_decode($layer->written(ProjectRoot::origin())->line(), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether a config sets a path, and what it sets there.
     *
     * @param  array<mixed>       $config
     * @return array{bool, mixed}
     */
    private static function at(array $config, string $path): array
    {
        $at = $config;

        foreach (explode('.', $path) as $key) {
            if (! is_array($at) || ! array_key_exists($key, $at)) {
                return [false, null];
            }

            $at = $at[$key];
        }

        return [true, $at];
    }
}
