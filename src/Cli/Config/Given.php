<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_key_exists;
use function array_map;
use function explode;
use function is_array;
use function is_string;

use Symfony\Component\Console\Input\InputInterface;

/**
 * What the command line says about the config: the file `--config` names,
 * and the settings `--runner`, `--report`, `--budget` and `--ci` lay over
 * everything else (ADR-0002).
 */
final readonly class Given
{
    /** @param list<string> $reports each as `--report` writes it, `<name>:<path>` */
    public function __construct(
        public string $config,
        public string $runner,
        public array $reports,
        public string $budget,
        public string $ci,
        public bool $firstPartyOnly,
    ) {
    }

    /** What a command's options say, where the command takes them. */
    public static function from(InputInterface $input): self
    {
        $reports = self::option($input, 'report');

        return new self(
            self::text(self::option($input, 'config')),
            self::text(self::option($input, 'runner')),
            self::texts($reports),
            self::text(self::option($input, 'budget')),
            self::text(self::option($input, 'ci')),
            $input->hasParameterOption('--no-extensions', onlyParams: true),
        );
    }

    /**
     * The settings the command line sets, as the last layer of the config.
     *
     * @return array<string, mixed>
     */
    public function layer(): array
    {
        $layer = [];

        if ($this->runner !== '') {
            $layer['runner'] = $this->runner;
        }

        if ($this->reports !== []) {
            $layer['reports'] = array_map($this->report(...), $this->reports);
        }

        if ($this->budget !== '') {
            $layer['budget'] = $this->budget;
        }

        if ($this->ci !== '') {
            $layer['ci'] = ['plan' => $this->ci];
        }

        return $layer;
    }

    /** @return array{use: string, path?: string} */
    private function report(string $report): array
    {
        $parts = explode(':', $report, 2);

        return array_key_exists(1, $parts) ? ['use' => $parts[0], 'path' => $parts[1]] : ['use' => $parts[0]];
    }

    private static function option(InputInterface $input, string $name): mixed
    {
        return $input->hasOption($name) ? $input->getOption($name) : '';
    }

    /** @return list<string> */
    private static function texts(mixed $values): array
    {
        $texts = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value)) {
                $texts[] = $value;
            }
        }

        return $texts;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
