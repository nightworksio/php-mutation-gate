<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Report\Form;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** `--format=text|json`, on a command that writes for a person by default, or as its public JSON. */
final readonly class FormOption
{
    private const string FORMAT = 'format';

    private const string UNKNOWN = '--format is %s; %s writes text or json.';

    public static function on(Command $command): Command
    {
        return $command->addOption(
            self::FORMAT,
            mode: InputOption::VALUE_REQUIRED,
            description: 'text or json',
            default: Form::Text->value,
        );
    }

    /** The form asked for, or why the command of this name refuses it. */
    public static function asked(InputInterface $input, string $command): Form|CannotJudge
    {
        $asked = $input->getOption(self::FORMAT);
        $written = is_string($asked) ? $asked : '';
        $form = Form::tryFrom($written);

        return $form instanceof Form ? $form : CannotJudge::because(sprintf(self::UNKNOWN, $written, $command));
    }
}
