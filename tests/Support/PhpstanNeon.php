<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_find;
use function array_values;
use function is_array;
use function is_string;

use Nette\Neon\Neon;

use function sprintf;
use function str_starts_with;

/** The lists phpstan.neon and the files of this repository it includes hand one of this repository's own rules, read as they are. */
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
        $service = array_find(
            self::services(),
            static fn(mixed $service): bool => is_array($service) && ($service['class'] ?? '') === $rule,
        );
        $arguments = is_array($service) ? $service['arguments'] ?? [] : [];

        return is_array($arguments) && is_array($arguments[$argument] ?? '') ? $arguments[$argument] : [];
    }

    /** @return list<mixed> the services phpstan.neon declares, and each file of this repository it includes */
    private static function services(): array
    {
        $configuration = Neon::decodeFile(Tree::at('phpstan.neon'));
        $configuration = is_array($configuration) ? $configuration : [];
        $includes = is_array($configuration['includes'] ?? null) ? $configuration['includes'] : [];
        $services = [];

        foreach (['phpstan.neon', ...array_filter($includes, is_string(...))] as $file) {
            $decoded = str_starts_with($file, 'vendor/') ? [] : Neon::decodeFile(Tree::at($file));
            $declared = is_array($decoded) && is_array($decoded['services'] ?? null) ? $decoded['services'] : [];
            $services = [...$services, ...array_values($declared)];
        }

        return $services;
    }
}
