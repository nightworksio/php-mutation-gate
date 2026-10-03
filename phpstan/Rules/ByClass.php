<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function sprintf;

/** Members phpstan.neon lists by class, each as `Class::member`. */
final readonly class ByClass
{
    /**
     * @param array<string, list<string>> $listed the members of each class
     *
     * @return list<string>
     */
    public static function named(array $listed): array
    {
        $named = [];

        foreach ($listed as $class => $members) {
            foreach ($members as $member) {
                $named[] = sprintf('%s::%s', $class, $member);
            }
        }

        return $named;
    }
}
