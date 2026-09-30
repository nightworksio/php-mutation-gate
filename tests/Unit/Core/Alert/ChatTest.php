<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Chat;

it('lets nothing the project wrote become Slack markup, a link or a mention', function (): void {
    expect(Chat::Slack->text('a <@U123> & <https://x|y>'))->toBe('a &lt;@U123&gt; &amp; &lt;https://x|y&gt;')
        ->and(Chat::Slack->code('src/`a`<b>.php'))->toBe("`src/'a'&lt;b&gt;.php`")
        ->and(Chat::Slack->heading('Why'))->toBe('*Why*');
});

it('lets nothing the project wrote become Discord Markdown', function (): void {
    expect(Chat::Discord->text('**bold** _i_ ~s~ `c` |x| > [a](b) # - \\'))
        ->toBe('\\*\\*bold\\*\\* \\_i\\_ \\~s\\~ \\`c\\` \\|x\\| \\> \\[a\\]\\(b\\) \\# \\- \\\\')
        ->and(Chat::Discord->code('src/`a`.php'))->toBe("`src/'a'.php`")
        ->and(Chat::Discord->heading('Why'))->toBe('**Why**');
});
