<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Editor;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * What VS Code reads to show survivors as Problems: a background task that
 * runs `watch --output=problems`, with the one problem matcher that reads its
 * lines, and the SARIF Viewer recommended for the `sarif` report. `init
 * --editor=vscode` writes these (ADR-0015, decisions 6 and 7).
 */
final readonly class VsCode
{
    /** The task's label, which names it in VS Code's task list. */
    public const string LABEL = 'mutation-gate: watch';

    /** The extension that shows a SARIF log's results in the source. */
    public const string SARIF_VIEWER = 'MS-SarifVSCode.sarif-viewer';

    private const string COMMAND = 'vendor/bin/mutation-gate watch --output=problems';

    private const string VERSION = '2.0.0';

    /** A whole line, matched as it is. */
    private const string WHOLE = '^%s$';

    /** `.vscode/tasks.json`, holding the task alone. */
    public static function tasks(): string
    {
        return JsonText::encode(['version' => self::VERSION, 'tasks' => [self::definition()]]);
    }

    /** The task, as the block to add to a `.vscode/tasks.json` that exists. */
    public static function task(): string
    {
        return JsonText::encode(self::definition());
    }

    /** `.vscode/extensions.json`, recommending the SARIF Viewer. */
    public static function extensions(): string
    {
        return JsonText::encode(['recommendations' => [self::SARIF_VIEWER]]);
    }

    /**
     * @return array{
     *     label: string,
     *     type: string,
     *     command: string,
     *     isBackground: bool,
     *     problemMatcher: array{
     *         owner: string,
     *         source: string,
     *         fileLocation: list<string>,
     *         pattern: array<string, string|int>,
     *         background: array{activeBegins: bool, beginsPattern: string, endsPattern: string},
     *     },
     * }
     */
    private static function definition(): array
    {
        return [
            'label' => self::LABEL,
            'type' => 'shell',
            'command' => self::COMMAND,
            'isBackground' => true,
            'problemMatcher' => [
                'owner' => ThisPackage::NAME,
                'source' => ThisPackage::NAME,
                'fileLocation' => ['relative', '${workspaceFolder}'],
                'pattern' => ['regexp' => Problems::PATTERN, ...Problems::GROUPS],
                'background' => [
                    'activeBegins' => false,
                    'beginsPattern' => sprintf(self::WHOLE, Problems::JUDGING),
                    'endsPattern' => sprintf(self::WHOLE, Problems::JUDGED),
                ],
            ],
        ];
    }
}
