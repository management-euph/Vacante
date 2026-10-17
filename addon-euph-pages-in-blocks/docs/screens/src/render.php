<?php

declare(strict_types=1);

/**
 * Draws the screens of the README: each template of the Page content block
 * rendered by Smarty 5 from the add-on's own templates (tests' TemplateHarness),
 * with sample pages, into an HTML page that loads the add-on's stylesheet and
 * script. Only the store around the block (fonts, buttons, the box title) is
 * an approximation of the Responsive theme: see theme.css.
 *
 *     php docs/screens/src/render.php <output dir> <compiled css>
 *
 * Needs the add-on's dev packages (composer install in app/addons/euph_pages_in_blocks).
 * The css is styles.less compiled the way the store does it (any LESS
 * compiler: the file is plain CSS). render.mjs then takes the screenshots.
 */

[$self, $out, $css] = $argv + [null, null, null];
if (!is_string($out) || !is_string($css)) {
    fwrite(STDERR, "usage: php render.php <output dir> <compiled css>\n");
    exit(2);
}

$addon = dirname(__DIR__, 3);
require $addon . '/app/addons/euph_pages_in_blocks/tests/bootstrap.php';

use Smarty\Template;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Pages;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\TemplateHarness;

$pages = [
    'checkin' => Pages::page(101, 'Check-in and check-out', '<p>Rooms are ready from <strong>14:00</strong> on the day you arrive; please leave them by <strong>11:00</strong> on the day you go.</p><ul><li>Early check-in from 10:00, when the room is free.</li><li>Late check-out until 15:00, for a fee.</li><li>The reception is open day and night.</li></ul>'),
    'payment' => Pages::page(102, 'Payment', '<p>You pay 30% when you book and the rest 14 days before you arrive, by card or by bank transfer. The card payment is secured by 3-D Secure; we never see your card number.</p>'),
    'cancel' => Pages::page(103, 'Cancellation', '<p>Cancel free of charge up to 21 days before arrival. From 20 to 7 days before, 30% of the stay is kept; later than that, the whole stay.</p><p>A booking can be moved to other dates once, free of charge, until 14 days before arrival.</p>'),
    'beach' => Pages::page(104, 'Beach and sunbeds', '<p>The hotel\'s private beach is 150 metres away. Two sunbeds and an umbrella per room are included from June to September; towels are at the reception.</p>'),
    'contact' => Pages::form(105, 'Ask us anything', '<p>Write to us and we answer within one working day.</p>'),
    'terms' => Pages::popup(106, 'Terms and conditions', '<p>The terms.</p>'),
    'partner' => Pages::link(107, 'Travel insurance', 'https://insurance.example/', true),
];

// What the Form builder add-on draws in pages:page_content for a form page.
$formBuilder = static function (Template $template): string {
    $page = $template->getValue('page');
    if (!is_array($page) || ($page['page_type'] ?? '') !== 'F') {
        return '';
    }

    return '<p>' . strip_tags((string) $page['description']) . '</p>'
        . '<form class="ty-form-builder" onsubmit="return false">'
        . '<div class="ty-control-group"><label class="ty-control-group__title cm-required">Your e-mail</label><input type="text" class="ty-input-text" size="50"></div>'
        . '<div class="ty-control-group"><label class="ty-control-group__title">Your question</label><textarea class="ty-input-textarea" rows="3"></textarea></div>'
        . '<div class="buttons-container"><button class="ty-btn ty-btn__secondary" type="submit">Submit</button></div>'
        . '</form>';
};

$screens = [
    'stacked' => ['One under another', 'stacked', [$pages['checkin'], $pages['contact'], $pages['terms']], [], 'sidebar'],
    'stacked-excerpt' => ['One under another, excerpts', 'stacked', [$pages['checkin'], $pages['payment'], $pages['cancel']], ['euph_pib_content' => 'excerpt', 'euph_pib_excerpt_length' => '110'], 'sidebar'],
    'tabs' => ['Tabs', 'tabs', [$pages['checkin'], $pages['payment'], $pages['cancel'], $pages['beach']], [], 'main'],
    'accordion' => ['Accordion', 'accordion', [$pages['checkin'], $pages['payment'], $pages['cancel'], $pages['partner']], ['euph_pib_open_first' => 'Y'], 'main'],
    'cards' => ['Cards', 'cards', [$pages['checkin'], $pages['payment'], $pages['cancel'], $pages['beach'], $pages['partner'], $pages['terms']], ['euph_pib_columns' => '3', 'euph_pib_excerpt_length' => '120'], 'main'],
];

is_dir($out) || mkdir($out, 0777, true);
copy($css, $out . '/styles.css');
copy(__DIR__ . '/theme.css', $out . '/theme.css');
copy($addon . '/js/addons/euph_pages_in_blocks/func.js', $out . '/func.js');

foreach ($screens as $name => [$title, $layout, $items, $properties, $place]) {
    $harness = new TemplateHarness();
    $harness->overrides['pages:page_content'] = $formBuilder;
    $block = $harness->block($layout, $items, Pages::block($properties, 12));
    $html = <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        <link rel="stylesheet" href="theme.css">
        <link rel="stylesheet" href="styles.css">
        </head>
        <body class="place-{$place}">
        <div class="shop">
            <div class="ty-sidebox">
                <h2 class="ty-sidebox__title">Good to know</h2>
                <div class="ty-sidebox__body">
        {$block}
                </div>
            </div>
        </div>
        <script>window.Tygh = { \$: null };</script>
        <script src="func.js"></script>
        </body>
        </html>
        HTML;
    file_put_contents($out . '/' . $name . '.html', $html);
    echo "{$name}.html\n";
}
