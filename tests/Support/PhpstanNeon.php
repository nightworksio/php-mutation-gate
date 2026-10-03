<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

use Nette\Neon\Neon;

use function sprintf;

/** The lists phpstan.neon hands one of this repository's own rules, read from the file as it is. */
final readonly class PhpstanNeon
{
    /**
     * The files a rule's argument names, relative to the repository.
     *
     * @return list<string>
     */
    public static function files(string $rule, string $argument): array
    {
        return array_values(array_filter(self::argumentOf($rule, $argument), is_string(...)));
    }

    /**
     * The constants or methods a rule's argument names by class, each as `Class::member`.
     *
     * @return list<string>
     */
    public static function members(string $rule, string $argument): array
    {
        $named = [];

        foreach (self::argumentOf($rule, $argument) as $class => $constants) {
            foreach (is_array($constants) ? array_filter($constants, is_string(...)) : [] as $constant) {
                $named[] = sprintf('%s::%s', $class, $constant);
            }
        }

        return $named;
    }

    /** @return array<mixed> what a rule's argument lists, as the file writes it */
    private static function argumentOf(string $rule, string $argument): array
    {
        $configuration = Neon::decodeFile(Tree::at('phpstan.neon'));

        foreach (is_array($configuration) && is_array($configuration['services']) ? $configuration['services'] : [] as $service) {
            $arguments = is_array($service) && ($service['class'] ?? '') === $rule ? $service['arguments'] ?? [] : [];

            if (is_array($arguments) && is_array($arguments[$argument] ?? '')) {
                return $arguments[$argument];
            }
        }

        return [];
    }
}
