<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function array_key_exists;
use function array_map;
use function explode;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What the command line says about the config: the file `--config` names,
 * and the settings `--runner`, `--report`, `--budget` and `--ci` lay over
 * everything else (ADR-0002).
 */
final readonly class CommandLine
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
        return new self(
            self::text(self::option($input, 'config')),
            self::text(self::option($input, 'runner')),
            self::texts(self::option($input, 'report')),
            self::text(self::option($input, 'budget')),
            self::text(self::option($input, 'ci')),
            $input->hasParameterOption('--no-extensions', onlyParams: true),
        );
    }

    /** What the command line says, but for a config file to read. */
    public function withoutConfig(): self
    {
        return new self('', $this->runner, $this->reports, $this->budget, $this->ci, $this->firstPartyOnly);
    }

    /** The settings the command line sets, as the last layer of the config, its paths named from the project. */
    public function layer(): Layer|Invalid
    {
        return Definition::layer(Node::config($this->written()->line()), ProjectRoot::origin());
    }

    /** The settings the command line sets, as a config file would write them. */
    public function written(): Json
    {
        $layer = Json::object();
        $layer = $this->runner === '' ? $layer : $layer->with('runner', $this->runner);
        $layer = $this->reports === []
            ? $layer
            : $layer->with('reports', Json::items(array_map(self::report(...), $this->reports)));
        $layer = $this->budget === '' ? $layer : $layer->with('budget', $this->budget);

        return $this->ci === '' ? $layer : $layer->with('ci', Json::object()->with('plan', $this->ci));
    }

    private static function report(string $report): Json
    {
        $parts = explode(':', $report, 2);
        $written = Json::object()->with('use', $parts[0]);

        return array_key_exists(1, $parts) ? $written->with('path', $parts[1]) : $written;
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
