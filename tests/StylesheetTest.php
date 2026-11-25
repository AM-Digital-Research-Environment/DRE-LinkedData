<?php
declare(strict_types=1);

/*
 * A modifier class exists to look different. One the templates emit with no
 * rule in the stylesheet is a state the visitor cannot see: the stale-dump
 * notice once rendered as the neutral one that way. Base and hook classes may
 * legitimately be unstyled (`dre-ld__heading`, `dre-ld__sparql`); modifiers
 * may not.
 */

test('every BEM modifier a template emits has a rule in the stylesheet', function (): void {
    $root = dirname(__DIR__);
    $css = (string) file_get_contents($root . '/asset/css/dre-linked-data.css');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/view', FilesystemIterator::SKIP_DOTS));
    $found = 0;
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'phtml') {
            continue;
        }
        preg_match_all('/\bdre-ld__[a-z0-9-]+--[a-z0-9-]+\b/', (string) file_get_contents($file->getPathname()), $matches);
        foreach (array_unique($matches[0]) as $modifier) {
            $found++;
            assertTrue(
                preg_match('/\.' . preg_quote($modifier, '/') . '(?![a-z0-9_-])/', $css) === 1,
                "{$file->getFilename()} emits {$modifier}, which asset/css/dre-linked-data.css never styles"
            );
        }
    }
    assertTrue($found > 0, 'the templates emit at least one modifier (the stale-dump notice)');
});

test('the stale-dump notice is drawn from the theme\'s warning tokens', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__) . '/asset/css/dre-linked-data.css');
    assertTrue(preg_match('/\.dre-ld__notice--stale\s*\{([^}]*)\}/', $css, $rule) === 1, 'no .dre-ld__notice--stale rule');
    assertContainsText('var(--warning-bg,', $rule[1]);
    assertContainsText('var(--warning,', $rule[1]);
});

/*
 * A grid with no `grid-template-columns` gets an implicit `auto` track, which
 * grows to its widest child's min-content: the query picker's longest option
 * once pushed the whole page 100px past a 390px viewport that way. Every grid
 * here names its columns, `minmax(0, 1fr)` for a single one.
 */
test('every grid in the stylesheet declares its columns', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__) . '/asset/css/dre-linked-data.css');
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    // Innermost blocks only, so a rule inside a media query is read on its own.
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    $grids = 0;
    foreach ($rules as [, $selector, $body]) {
        if (preg_match('/(?<![a-z-])display\s*:\s*(inline-)?grid\b/', $body) !== 1) {
            continue;
        }
        $grids++;
        assertTrue(
            preg_match('/(?<![a-z-])grid-template-columns\s*:/', $body) === 1,
            trim($selector) . ' sets display: grid without grid-template-columns, so its implicit auto track can outgrow a narrow viewport'
        );
    }
    assertTrue($grids > 0, 'the stylesheet has at least one grid');
});
