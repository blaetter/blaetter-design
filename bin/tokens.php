<?php

declare(strict_types=1);

/*
 * Reads tokens.json, checks it (format, contrast of every listed pair in both
 * themes) and writes the files in dist/:
 *
 *   tokens.css       light values as custom properties (--blaetter-*), plus
 *                    the --palm-color-* and --palm-space-* names the website
 *                    theme uses; the spacing scale; the breakpoints as values
 *                    and media queries (for scripts)
 *   tokens-dark.css  dark values of the same properties, for pages with a dark theme
 *   tokens.scss      light values, spacing and breakpoints as Sass variables ($blaetter-*),
 *                    for the Sass build of the website's legacy styles (blaetter-theme)
 *   archive.json     plain hex values, the light → dark map, the spacing in px and
 *                    the media queries of the offline archive
 *   contrast.md      every checked pair with its contrast, for review
 *   index.html       overview for people: colours (light, dark, covers), fonts,
 *                    spacing, breakpoints, grounds and every contrast pair as a
 *                    live sample; uses tokens.css and tokens-dark.css beside it
 *                    and the fonts and logo in assets/; looks like the archive's
 *                    design system (header, headings, page width)
 *
 * Usage: php bin/tokens.php build   write dist/
 *        php bin/tokens.php check   exit 1 when dist/ is out of date or a pair fails
 *
 * No dependencies besides PHP 8.2 or later.
 */

const KINDS = ['text', 'background', 'border'];

$root = dirname(__DIR__);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['build', 'check'], true)) {
    fwrite(STDERR, "Usage: php bin/tokens.php build|check\n");
    exit(2);
}

try {
    $tokens = json_decode((string) file_get_contents($root . '/tokens.json'), true, 512, JSON_THROW_ON_ERROR);
    $model = model($tokens);
    $failures = contrastFailures($model);
    $files = [
        'tokens.css' => tokensCss($model),
        'tokens-dark.css' => tokensDarkCss($model),
        'tokens.scss' => tokensScss($model),
        'archive.json' => archiveJson($model),
        'contrast.md' => contrastMd($model),
        'index.html' => indexHtml($model),
    ];
} catch (Throwable $e) {
    fwrite(STDERR, 'tokens.json: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($failures as $line) {
    fwrite(STDERR, 'Contrast too low: ' . $line . "\n");
}

if ($mode === 'build') {
    if (!is_dir($root . '/dist')) {
        mkdir($root . '/dist');
    }
    foreach ($files as $name => $content) {
        file_put_contents($root . '/dist/' . $name, $content);
    }
    echo 'Wrote ' . implode(', ', array_map(static fn (string $n): string => 'dist/' . $n, array_keys($files))) . "\n";
    exit($failures === [] ? 0 : 1);
}

$stale = array_keys(array_filter($files, static fn (string $content, string $name): bool => @file_get_contents($root . '/dist/' . $name) !== $content, ARRAY_FILTER_USE_BOTH));
foreach ($stale as $name) {
    fwrite(STDERR, "dist/$name does not match tokens.json, run build\n");
}
if ($stale === [] && $failures === []) {
    echo "dist/ matches tokens.json, all contrast pairs pass\n";
}
exit($stale === [] && $failures === [] ? 0 : 1);

/**
 * Normalised tokens: colours with a light value and, where the token has a dark
 * theme, its dark default and one dark value per kind (text, background, border).
 *
 * @return array{colors: array<string, array{light: string, dark: ?array<string, string>, palm: ?string, use: string, note: string}>, fonts: array<string, array{stack: list<string>, use: string}>, pairs: list<array{fg: string, bg: string, min: float}>}
 */
function model(array $tokens): array
{
    $colors = [];
    foreach ($tokens['colors'] ?? [] as $name => $t) {
        $colors[$name] = color($name, $t);
    }
    foreach ($tokens['cover'] ?? [] as $name => $t) {
        if (str_starts_with($name, '$')) {
            continue;
        }
        $light = hex("cover.$name", $t['light'] ?? null);
        // As the archive's script shows them on dark pages: 80 % colour, 20 % white.
        $dark = mix($light, '#FFFFFF', 0.8);
        $colors["cover.$name"] = [
            'light' => $light,
            'dark' => array_fill_keys(['default', ...KINDS], $dark),
            'palm' => null,
            'use' => 'cover ' . ($t['covers'] ?? ''),
            'note' => '',
        ];
    }

    $fonts = [];
    foreach ($tokens['fonts'] ?? [] as $name => $f) {
        if (!is_array($f['stack'] ?? null) || $f['stack'] === []) {
            throw new RuntimeException("fonts.$name: \"stack\" must be a non-empty list");
        }
        $fonts[$name] = ['stack' => array_values($f['stack']), 'use' => (string) ($f['use'] ?? '')];
    }

    $pairs = [];
    foreach ($tokens['contrast']['pairs'] ?? [] as $i => $p) {
        $as = (string) ($p['as'] ?? 'text');
        if (!in_array($as, KINDS, true)) {
            throw new RuntimeException(sprintf('contrast pair #%d: "as" is one of %s', $i + 1, implode(', ', KINDS)));
        }
        foreach (expand((string) ($p['fg'] ?? ''), $colors) as $fg) {
            foreach (expand((string) ($p['bg'] ?? ''), $colors) as $bg) {
                $pairs[] = ['fg' => $fg, 'bg' => $bg, 'min' => (float) ($p['min'] ?? 4.5), 'as' => $as];
            }
        }
        if (!isset($p['min'])) {
            throw new RuntimeException(sprintf('contrast pair #%d: "min" is missing', $i + 1));
        }
    }

    // The archive maps by light value: tokens sharing one must agree on the dark side.
    $byHex = [];
    foreach ($colors as $name => $c) {
        if ($c['dark'] === null) {
            continue;
        }
        if (isset($byHex[$c['light']]) && $colors[$byHex[$c['light']]]['dark'] !== $c['dark']) {
            throw new RuntimeException(sprintf('%s and %s share %s but differ in the dark theme', $byHex[$c['light']], $name, $c['light']));
        }
        $byHex[$c['light']] = $name;
    }

    $breakpoints = [];
    foreach ($tokens['breakpoints'] ?? [] as $name => $b) {
        if (str_starts_with($name, '$')) {
            continue;
        }
        $breakpoints[$name] = breakpoint($name, $b);
    }

    $spacing = [];
    foreach ($tokens['spacing'] ?? [] as $name => $sp) {
        if (str_starts_with($name, '$')) {
            continue;
        }
        $spacing[$name] = spacing($name, $sp);
    }

    return ['colors' => $colors, 'fonts' => $fonts, 'pairs' => $pairs, 'breakpoints' => $breakpoints, 'spacing' => $spacing];
}

/**
 * A step of the spacing scale in whole px. Steps off the 4 px grid need a note
 * that says why they exist.
 *
 * @return array{px: int, palm: ?string, use: string, note: string}
 */
function spacing(string $name, array $sp): array
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
        throw new RuntimeException("spacing.$name: name in lower case letters, digits and hyphens");
    }
    $unknown = array_diff(array_keys($sp), ['px', 'palm', 'use', 'note']);
    if ($unknown !== []) {
        throw new RuntimeException("spacing.$name: unknown key " . implode(', ', $unknown));
    }
    if (!is_int($sp['px'] ?? null) || $sp['px'] <= 0) {
        throw new RuntimeException("spacing.$name: \"px\" is a width in whole px");
    }
    if ($sp['px'] % 4 !== 0 && trim((string) ($sp['note'] ?? '')) === '') {
        throw new RuntimeException("spacing.$name: {$sp['px']} px is off the 4 px scale, a \"note\" has to say why");
    }

    return ['px' => $sp['px'], 'palm' => $sp['palm'] ?? null, 'use' => (string) ($sp['use'] ?? ''), 'note' => (string) ($sp['note'] ?? '')];
}

/**
 * A breakpoint is either the upper end of a step ("max", e.g. phones up to 720 px)
 * or the lower end ("min"), in whole px.
 *
 * @return array{px: int, edge: string, use: string}
 */
function breakpoint(string $name, array $b): array
{
    if (!preg_match('/^[a-z][a-z-]*$/', $name)) {
        throw new RuntimeException("breakpoints.$name: name in lower case letters and hyphens");
    }
    $unknown = array_diff(array_keys($b), ['min', 'max', 'use']);
    if ($unknown !== []) {
        throw new RuntimeException("breakpoints.$name: unknown key " . implode(', ', $unknown));
    }
    $edges = array_values(array_intersect(['min', 'max'], array_keys($b)));
    if (count($edges) !== 1 || !is_int($b[$edges[0]]) || $b[$edges[0]] <= 0) {
        throw new RuntimeException("breakpoints.$name: exactly one of \"min\" or \"max\", a width in whole px");
    }

    return ['px' => $b[$edges[0]], 'edge' => $edges[0], 'use' => (string) ($b['use'] ?? '')];
}

function rem(float $px): string
{
    return rtrim(rtrim(sprintf('%.3f', $px / 16), '0'), '.') . 'rem';
}

/**
 * Media queries per name: "<name>" for the step itself, plus "above-<name>" for the
 * screens above a "max" step (its width + 0.001rem, as the website's legacy styles
 * write it, so the two never overlap).
 *
 * @return array<string, string>
 */
function mediaQueries(array $model): array
{
    $out = [];
    foreach ($model['breakpoints'] as $name => $b) {
        $out[$name] = sprintf('(%s-width: %s)', $b['edge'], rem($b['px']));
        if ($b['edge'] === 'max') {
            $out["above-$name"] = sprintf('(min-width: %s)', rtrim(rtrim(sprintf('%.3f', $b['px'] / 16 + 0.001), '0'), '.') . 'rem');
        }
    }

    return $out;
}

/** @return array{light: string, dark: ?array<string, string>, palm: ?string, use: string, note: string} */
function color(string $name, array $t): array
{
    $unknown = array_diff(array_keys($t), ['light', 'dark', 'palm', 'use', 'note']);
    if ($unknown !== []) {
        throw new RuntimeException("colors.$name: unknown key " . implode(', ', $unknown));
    }
    $light = hex($name, $t['light'] ?? null);
    $dark = null;
    if (isset($t['dark'])) {
        $d = is_array($t['dark']) ? $t['dark'] : ['default' => $t['dark']];
        if (array_diff(array_keys($d), ['default', ...KINDS]) !== [] || !isset($d['default'])) {
            throw new RuntimeException("colors.$name.dark: a colour or an object with \"default\" and optionally text, background, border");
        }
        $dark = ['default' => hex("$name.dark", $d['default'])];
        foreach (KINDS as $kind) {
            $dark[$kind] = hex("$name.dark.$kind", $d[$kind] ?? $d['default']);
        }
    }

    return ['light' => $light, 'dark' => $dark, 'palm' => $t['palm'] ?? null, 'use' => (string) ($t['use'] ?? ''), 'note' => (string) ($t['note'] ?? '')];
}

function hex(string $where, mixed $value): string
{
    if (!is_string($value) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
        throw new RuntimeException("$where: expected a colour \"#RRGGBB\"");
    }

    return strtoupper($value);
}

/** @return list<string> token names matching "name" or "group.*" */
function expand(string $pattern, array $colors): array
{
    $names = array_values(array_filter(array_keys($colors), static fn (string $n): bool => fnmatch($pattern, $n)));
    if ($names === []) {
        throw new RuntimeException("contrast: no colour matches \"$pattern\"");
    }

    return $names;
}

function mix(string $color, string $other, float $part): string
{
    $out = '#';
    for ($i = 1; $i < 7; $i += 2) {
        $out .= sprintf('%02X', (int) round(hexdec(substr($color, $i, 2)) * $part + hexdec(substr($other, $i, 2)) * (1 - $part)));
    }

    return $out;
}

/** WCAG 2 contrast ratio */
function ratio(string $a, string $b): float
{
    $l = static function (string $hex): float {
        $sum = 0.0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
            $c = hexdec(substr($hex, 1 + 2 * $i, 2)) / 255;
            $sum += $weight * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }

        return $sum;
    };
    [$x, $y] = [$l($a), $l($b)];

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/**
 * Every pair in both themes: [theme, fg name, fg hex, bg name, bg hex, ratio, min].
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float, 6: float}>
 */
function contrastRows(array $model): array
{
    $rows = [];
    foreach ($model['pairs'] as $p) {
        $fg = $model['colors'][$p['fg']];
        $bg = $model['colors'][$p['bg']];
        $label = $p['as'] === 'text' ? $p['fg'] : $p['fg'] . ' (' . $p['as'] . ')';
        $rows[] = ['hell', $label, $fg['light'], $p['bg'], $bg['light'], ratio($fg['light'], $bg['light']), $p['min']];
        if ($fg['dark'] !== null && $bg['dark'] !== null) {
            $rows[] = ['dunkel', $label, $fg['dark'][$p['as']], $p['bg'], $bg['dark']['background'], ratio($fg['dark'][$p['as']], $bg['dark']['background']), $p['min']];
        }
    }

    return $rows;
}

/** @return list<string> */
function contrastFailures(array $model): array
{
    $out = [];
    foreach (contrastRows($model) as [$theme, $fg, $fgHex, $bg, $bgHex, $ratio, $min]) {
        // Round down like the report, so 4.49 never passes as 4.5.
        if (floor($ratio * 100) / 100 < $min) {
            $out[] = sprintf('%s on %s (%s): %s on %s = %.2f:1, needs %s:1', $fg, $bg, $theme, $fgHex, $bgHex, $ratio, $min);
        }
    }

    return $out;
}

function property(string $name): string
{
    return '--blaetter-' . (str_starts_with($name, 'cover.') ? 'cover-' . substr($name, 6) : 'color-' . $name);
}

function banner(string $what): string
{
    return "/* Generated from tokens.json (blaetter-design), do not edit. $what */\n";
}

function tokensCss(array $model): string
{
    $lines = [];
    foreach ($model['colors'] as $name => $c) {
        $lines[] = sprintf('  %s: %s;', property($name), strtolower($c['light']));
    }
    $lines[] = '';
    foreach ($model['fonts'] as $name => $f) {
        $lines[] = sprintf('  --blaetter-font-%s: %s;', $name, fontStack($f['stack']));
    }
    $lines[] = '';
    foreach ($model['spacing'] as $name => $sp) {
        $lines[] = sprintf('  --blaetter-space-%s: %s;', $name, rem($sp['px']));
    }
    $lines[] = '';
    $lines[] = '  /* Breakpoints: media queries cannot read these, CSS writes the queries out; for scripts (matchMedia). */';
    foreach ($model['breakpoints'] as $name => $b) {
        $lines[] = sprintf('  --blaetter-breakpoint-%s: %s;', $name, rem($b['px']));
    }
    foreach (mediaQueries($model) as $name => $query) {
        $lines[] = sprintf('  --blaetter-media-%s: %s;', $name, $query);
    }
    $lines[] = '';
    $lines[] = '  /* Names of the website theme (palm) */';
    foreach ($model['colors'] as $name => $c) {
        if ($c['palm'] !== null) {
            $lines[] = sprintf('  --palm-color-%s: var(%s);', $c['palm'], property($name));
        }
    }
    foreach ($model['spacing'] as $name => $sp) {
        if ($sp['palm'] !== null) {
            $lines[] = sprintf('  --palm-space-%s: var(--blaetter-space-%s);', $sp['palm'], $name);
        }
    }

    return banner('Light values.') . ":root {\n" . implode("\n", $lines) . "\n}\n";
}

function tokensScss(array $model): string
{
    $lines = [];
    foreach ($model['colors'] as $name => $c) {
        $lines[] = sprintf('%s: %s;', '$' . substr(property($name), 2), strtolower($c['light']));
    }
    $lines[] = '';
    foreach ($model['fonts'] as $name => $f) {
        $lines[] = sprintf('$blaetter-font-%s: %s;', $name, fontStack($f['stack']));
    }
    $lines[] = '';
    foreach ($model['spacing'] as $name => $sp) {
        $lines[] = sprintf('$blaetter-space-%s: %s;', $name, rem($sp['px']));
    }
    $lines[] = '';
    foreach ($model['breakpoints'] as $name => $b) {
        $lines[] = sprintf('$blaetter-breakpoint-%s: %s;', $name, rem($b['px']));
    }
    foreach (mediaQueries($model) as $name => $query) {
        $lines[] = sprintf('$blaetter-media-%s: "%s";', $name, $query);
    }

    return "// Generated from tokens.json (blaetter-design), do not edit. Light values, spacing and breakpoints as Sass variables.\n" . implode("\n", $lines) . "\n";
}

/** @param list<string> $stack */
function fontStack(array $stack): string
{
    return implode(', ', array_map(static fn (string $s): string => in_array($s, ['serif', 'sans-serif', 'monospace'], true) ? $s : '"' . $s . '"', $stack));
}

function tokensDarkCss(array $model): string
{
    $lines = [];
    foreach ($model['colors'] as $name => $c) {
        if ($c['dark'] !== null) {
            // A custom property has one value: the default; the variants per kind are for the archive.
            $lines[] = sprintf('  %s: %s;', property($name), strtolower($c['dark']['default']));
        }
    }
    $block = implode("\n", $lines);

    return banner('Dark values: follows the system unless data-theme="light" is set; data-theme="dark" forces them.')
        . "@media (prefers-color-scheme: dark) {\n  :root:not([data-theme=\"light\"]) {\n" . preg_replace('/^/m', '  ', $block) . "\n    color-scheme: dark;\n  }\n}\n"
        . ":root[data-theme=\"dark\"] {\n" . $block . "\n  color-scheme: dark;\n}\n";
}

function archiveJson(array $model): string
{
    $colors = [];
    $map = [];
    $cover = [];
    foreach ($model['colors'] as $name => $c) {
        if (str_starts_with($name, 'cover.')) {
            $cover[substr($name, 6)] = ['light' => $c['light'], 'dark' => $c['dark']['default']];
            continue;
        }
        $colors[$name] = $c['light'];
        if ($c['dark'] !== null) {
            // Light value => dark twin as text, background, border; null keeps the light value.
            $map[substr($c['light'], 1)] = array_map(static fn (string $kind): ?string => $c['dark'][$kind] === $c['light'] ? null : substr($c['dark'][$kind], 1), KINDS);
        }
    }

    return json_encode(['$comment' => 'Generated from tokens.json (blaetter-design), do not edit.', 'colors' => $colors, 'darkMap' => $map, 'cover' => $cover, 'spacing' => array_map(static fn (array $sp): int => $sp['px'], $model['spacing']), 'media' => mediaQueries($model)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

function contrastMd(array $model): string
{
    $out = "<!-- Generated from tokens.json, do not edit. -->\n# Kontraste\n\n| Theme | Vordergrund | | Hintergrund | | Kontrast | Mindestens | |\n|---|---|---|---|---|---:|---:|---|\n";
    foreach (contrastRows($model) as [$theme, $fg, $fgHex, $bg, $bgHex, $ratio, $min]) {
        $out .= sprintf("| %s | %s | `%s` | %s | `%s` | %.2f:1 | %s:1 | %s |\n", $theme, $fg, $fgHex, $bg, $bgHex, floor($ratio * 100) / 100, $min, floor($ratio * 100) / 100 >= $min ? 'ok' : '**zu niedrig**');
    }

    return $out;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** A swatch: colour field, hex, name, then optional lines. */
function swatch(string $hex, string $title, string ...$lines): string
{
    $out = sprintf('<li><span class="ds-swatch" style="background:%s"></span><strong>%s</strong> %s', $hex, $hex, e($title));
    foreach ($lines as $line) {
        if ($line !== '') {
            $out .= '<br>' . $line;
        }
    }

    return $out . "</li>\n";
}

/**
 * The overview page. Its own chrome uses the generated custom properties
 * (tokens.css, tokens-dark.css next to it), so it follows the light and dark
 * theme like the consumers; the samples set their hex values inline, so each
 * shows exactly the pair it names, whatever theme is active.
 */
function indexHtml(array $model): string
{
    $base = [];
    $covers = [];
    foreach ($model['colors'] as $name => $c) {
        if (str_starts_with($name, 'cover.')) {
            $covers[substr($name, 6)] = $c;
        } else {
            $base[$name] = $c;
        }
    }

    // Colours, light
    $light = '';
    $websiteOnly = [];
    foreach ($base as $name => $c) {
        if ($c['dark'] === null) {
            $websiteOnly[] = $name;
        }
        $light .= swatch(
            $c['light'],
            $name,
            $c['palm'] !== null ? '<code>--palm-color-' . e($c['palm']) . '</code>' : '<code>' . e(property($name)) . '</code>',
            e($c['use']),
            $c['note'] !== '' ? '<span class="ds-muted">' . e($c['note']) . '</span>' : '',
        );
    }

    // Colours, dark
    $dark = '';
    foreach ($base as $name => $c) {
        if ($c['dark'] === null) {
            continue;
        }
        $kinds = [];
        foreach (KINDS as $kind) {
            if ($c['dark'][$kind] !== $c['dark']['default']) {
                $kinds[] = sprintf('as %s <span class="ds-chip" style="background:%s"></span> %s', $kind, $c['dark'][$kind], $c['dark'][$kind]);
            }
        }
        $dark .= swatch(
            $c['dark']['default'],
            $name,
            'for ' . $c['light'] . ' <span class="ds-chip" style="background:' . $c['light'] . '"></span>',
            implode('<br>', $kinds),
        );
    }

    // Covers
    $cover = '';
    foreach ($covers as $name => $c) {
        $cover .= swatch(
            $c['light'],
            $name,
            e($c['use']),
            'dark: ' . $c['dark']['default'] . ' <span class="ds-chip" style="background:' . $c['dark']['default'] . '"></span>',
        );
    }

    // Fonts
    $fonts = '';
    foreach ($model['fonts'] as $name => $f) {
        $fonts .= sprintf(
            "<tr><td><code>--blaetter-font-%s</code><br><span class=\"ds-muted\">%s</span></td><td>%s</td><td class=\"ds-sample\" style=\"font-family:%s\">Eine Insel der Vernunft in einem Meer von Unsinn</td></tr>\n",
            e($name),
            e(implode(', ', $f['stack'])),
            e($f['use']),
            e(fontStack($f['stack'])),
        );
    }

    // Spacing
    $spacing = '';
    foreach ($model['spacing'] as $name => $sp) {
        $spacing .= sprintf(
            "<tr><td><code>%s</code></td><td>%d&nbsp;px<br><span class=\"ds-muted\">%s</span></td><td><span class=\"ds-bar\" style=\"width:%dpx\"></span></td><td>%s%s%s</td></tr>\n",
            e($name),
            $sp['px'],
            rem($sp['px']),
            $sp['px'],
            $sp['palm'] !== null ? '<code>--palm-space-' . e($sp['palm']) . '</code><br>' : '',
            e($sp['use']),
            $sp['note'] !== '' ? '<br><span class="ds-muted">' . e($sp['note']) . '</span>' : '',
        );
    }

    // Breakpoints
    $breakpoints = '';
    $queries = mediaQueries($model);
    foreach ($queries as $name => $query) {
        $own = $model['breakpoints'][$name] ?? null;
        $use = $own !== null ? $own['use'] : 'everything that is not ' . substr($name, 6);
        $px = $own !== null ? ($own['edge'] === 'max' ? 'up to ' : 'from ') . $own['px'] . '&nbsp;px' : 'from ' . $model['breakpoints'][substr($name, 6)]['px'] . '&nbsp;px';
        $breakpoints .= sprintf("<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>\n", e($name), e($query), $px, e($use));
    }

    // Contrast
    $contrast = '';
    $failed = 0;
    foreach (contrastRows($model) as [$theme, $fg, $fgHex, $bg, $bgHex, $ratio, $min]) {
        $shown = floor($ratio * 100) / 100;
        $ok = $shown >= $min;
        $failed += $ok ? 0 : 1;
        $sample = str_contains($fg, '(border)')
            ? sprintf('<span class="ds-pair" style="background:%s"><span class="ds-frame" style="border-color:%s"></span></span>', $bgHex, $fgHex)
            : sprintf('<span class="ds-pair" style="background:%s;color:%s">Blätter Aa</span>', $bgHex, $fgHex);
        $contrast .= sprintf(
            "<tr data-theme-row=\"%s\"><td>%s</td><td>%s</td><td>%s <span class=\"ds-muted\">%s</span></td><td>%s <span class=\"ds-muted\">%s</span></td><td class=\"ds-num\">%.2f:1</td><td class=\"ds-num\">%s:1</td><td>%s</td></tr>\n",
            $theme === 'hell' ? 'light' : 'dark',
            $sample,
            $theme === 'hell' ? 'light' : 'dark',
            e($fg),
            $fgHex,
            e($bg),
            $bgHex,
            $shown,
            rtrim(rtrim(sprintf('%.1f', $min), '0'), '.'),
            $ok ? '<span class="ds-ok">ok</span>' : '<strong class="ds-fail">too low</strong>',
        );
    }
    $pairCount = count(contrastRows($model));
    $summary = $failed === 0 ? "all $pairCount pass" : "$failed of $pairCount too low";

    $websiteOnlyList = implode(', ', array_map(static fn (string $n): string => '<code>' . e($n) . '</code>', $websiteOnly));
    $surface = $base['surface']['light'];
    $page = $base['page']['light'];
    $muted = $base['muted']['light'];
    $success = $base['success']['light'] ?? $base['link']['light'];
    $text = $base['text']['light'];

    // Header, first heading, sections and documentation chrome follow the archive's
    // design system (blaetter-archiv: assets/archiv.css, docs/design-system.html),
    // with the token custom properties instead of hex values, so the dark theme of
    // tokens-dark.css applies. Fonts and logo come from assets/ of this repository.
    $css = <<<'CSS'
  @font-face { font-family: "droid-serif"; font-weight: 400; font-style: normal; src: url("../assets/fonts/droid-serif-latin-400.woff") format("woff"); }
  @font-face { font-family: "droid-serif"; font-weight: 400; font-style: italic; src: url("../assets/fonts/droid-serif-latin-400italic.woff") format("woff"); }
  @font-face { font-family: "droid-serif"; font-weight: 700; font-style: normal; src: url("../assets/fonts/droid-serif-latin-700.woff") format("woff"); }
  @font-face { font-family: "droid-sans"; font-weight: 400; font-style: normal; src: url("../assets/fonts/droid-sans-latin-400.woff") format("woff"); }
  @font-face { font-family: "droid-sans"; font-weight: 700; font-style: normal; src: url("../assets/fonts/droid-sans-latin-700.woff") format("woff"); }
  @font-face { font-family: "playfair"; font-weight: 700; font-style: normal; src: url("../assets/fonts/playfair-display-bold.woff") format("woff"); }

  /* Base, as in archiv.css */
  html { -webkit-text-size-adjust: 100%; overflow-y: scroll; background: var(--blaetter-color-page); }
  body { margin: 0; background: var(--blaetter-color-page); color: var(--blaetter-color-text); font-family: var(--blaetter-font-serif); font-size: 18px; line-height: 1.6; }
  a { color: var(--blaetter-color-link); text-decoration: none; }
  a:hover, a:focus { text-decoration: underline; }
  a:focus, button:focus-visible { outline: 2px solid var(--blaetter-color-focus); outline-offset: 2px; }
  h1, h2, h3 { font-weight: 700; line-height: 1.2; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 0 24px; }
  .wrap:after { content: ""; display: table; clear: both; }
  .visually-hidden { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); border: 0; white-space: nowrap; }
  .skip { position: absolute; left: -9999px; top: 0; background: var(--blaetter-color-page); padding: 8px 12px; }
  .skip:focus { left: 8px; z-index: 11; }
  [hidden] { display: none; }

  /* Header with logo, navigation, theme button and phone menu, as in archiv.css */
  .site-header { border-bottom: 1px solid var(--blaetter-color-rule); padding: var(--blaetter-space-s) 0; background: var(--blaetter-color-page); }
  .brand { float: left; line-height: 0; }
  .brand:hover, .brand:focus { text-decoration: none; }
  .brand img { vertical-align: middle; }
  .brand .palm { width: 40px; height: 40px; }
  .brand .claim { width: 120px; height: 25.6px; margin-left: 7px; }
  .nav { float: right; font-family: var(--blaetter-font-sans); font-size: 16px; }
  .nav ul { margin: 0; padding: 0; list-style: none; }
  .nav li { display: inline-block; margin-left: var(--blaetter-space-l); line-height: 40px; }
  .nav a { color: var(--blaetter-color-text); }
  .nav .active a { color: var(--blaetter-color-red); }
  .header-sticky .site-header { margin-bottom: 0; }
  .header-compact .site-header { padding: var(--blaetter-space-xs) 0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
  .header-compact .brand .palm { width: 30px; height: 30px; }
  .header-compact .brand .claim { width: 100px; height: 21.35px; }
  .header-compact .nav li { line-height: 30px; }
  .header-compact .theme-toggle { height: 30px; }
  @media (prefers-reduced-motion: no-preference) {
    .header-sticky .site-header { transition: padding 0.3s ease-out, margin-bottom 0.3s ease-out, box-shadow 0.3s ease-out; }
    .header-sticky .brand img { transition: width 0.3s ease-out, height 0.3s ease-out; }
    .header-sticky .nav li { transition: line-height 0.3s ease-out; }
    .header-sticky .theme-toggle { transition: height 0.3s ease-out; }
    .header-sticky .sticky-below-header { transition: top 0.3s ease-out; }
  }
  .header-measure .site-header, .header-measure .brand img, .header-measure .nav li, .header-measure .theme-toggle { transition: none; }
  .theme-toggle { float: right; width: 40px; height: 40px; margin: 0 -10px 0 12px; padding: 0; border: 0; background: transparent; cursor: pointer; }
  .theme-icon { position: relative; display: block; width: 14px; height: 14px; margin: 0 auto; border: 2px solid var(--blaetter-color-muted); border-radius: 50%; overflow: hidden; }
  .theme-icon:before { content: ""; position: absolute; top: 0; right: 0; bottom: 0; border-left: 7px solid var(--blaetter-color-muted); }
  .theme-toggle:hover .theme-icon, .theme-toggle:focus .theme-icon, .theme-toggle:hover .theme-icon:before, .theme-toggle:focus .theme-icon:before { border-color: var(--blaetter-color-link); }
  .menu-toggle { display: none; }
  .menu-icon, .menu-icon:before, .menu-icon:after { display: block; width: 24px; height: 3px; background: var(--blaetter-color-text); }
  .menu-icon { position: relative; margin: 0 auto; }
  .menu-icon:before, .menu-icon:after { content: ""; position: absolute; left: 0; }
  .menu-icon:before { top: -8px; }
  .menu-icon:after { top: 8px; }
  .menu-toggle[aria-expanded="true"] .menu-icon { background: transparent; }
  .menu-toggle[aria-expanded="true"] .menu-icon:before { top: 0; transform: rotate(45deg); }
  .menu-toggle[aria-expanded="true"] .menu-icon:after { top: 0; transform: rotate(-45deg); }

  /* Headings, as in archiv.css: bold sans serif in capitals, red label, thick rule below */
  .heading { margin: 0 0 10px; padding-bottom: 4px; border-bottom: 5px solid var(--blaetter-color-rule-strong); color: var(--blaetter-color-text); font-family: var(--blaetter-font-sans); font-size: 22px; letter-spacing: 0.02em; text-transform: uppercase; }
  .heading-label { color: var(--blaetter-color-red); }
  .page-head { padding: 32px 0 8px; }
  .page-head .heading { font-size: 28px; }
  .lede { max-width: 40em; margin: 12px 0 0; }
  .lede p { margin: 0; }
  .divider { margin: 0 0 6px; padding: 6px 0 3px; border-bottom: 5px solid var(--blaetter-color-rule-strong); background: var(--blaetter-color-page); color: var(--blaetter-color-text); font-family: var(--blaetter-font-sans); font-size: 16px; letter-spacing: 0.02em; text-transform: uppercase; }

  /* Documentation chrome, as in the archive's docs/design-system.html (prefix ds-) */
  .ds-intro { max-width: 46em; }
  .ds-toc { margin: 20px 0 0; padding: 0; list-style: none; font-family: var(--blaetter-font-sans); font-size: 15px; columns: 3; }
  .ds-toc li { margin: 0 0 4px; }
  .ds-section { margin-top: 56px; }
  .ds-item { margin-top: 36px; }
  .ds-item > h3 { margin: 0 0 6px; font-family: var(--blaetter-font-sans); font-size: 18px; }
  .ds-note { max-width: 46em; margin: 0 0 12px; font-family: var(--blaetter-font-sans); font-size: 15px; line-height: 1.55; }
  ul.ds-note { padding-left: 1.3em; }
  .ds-note code, .ds-table code { font-family: Menlo, Consolas, monospace; font-size: 0.88em; background: var(--blaetter-color-surface); padding: 1px 4px; }
  .ds-muted { color: var(--blaetter-color-muted); }
  .ds-swatches { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px 2%; margin: 0; padding: 0; list-style: none; font-family: var(--blaetter-font-sans); font-size: 14px; line-height: 1.45; }
  .ds-swatch { display: block; height: 56px; margin-bottom: 6px; border: 1px solid var(--blaetter-color-rule); }
  .ds-chip { display: inline-block; width: 0.9em; height: 0.9em; vertical-align: -0.1em; border: 1px solid var(--blaetter-color-rule); }
  .ds-table { width: 100%; border-collapse: collapse; font-family: var(--blaetter-font-sans); font-size: 15px; }
  .ds-table th, .ds-table td { padding: 6px 10px 6px 0; border-bottom: 1px solid var(--blaetter-color-rule); text-align: left; vertical-align: top; }
  .ds-scroll { overflow-x: auto; }
  .ds-num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .ds-sample { font-size: 18px; }
  .ds-bar { display: inline-block; height: 16px; background: var(--blaetter-color-red); vertical-align: middle; }
  .ds-pair { display: inline-block; min-width: 7.5em; padding: 4px 10px; font-family: var(--blaetter-font-sans); font-size: 16px; border: 1px solid var(--blaetter-color-rule); white-space: nowrap; }
  .ds-frame { display: inline-block; width: 4.5em; height: 1.3em; border: 2px solid; vertical-align: middle; }
  .ds-ok { color: var(--blaetter-color-muted); }
  .ds-fail { color: var(--blaetter-color-red); }
  .ds-grounds { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  .ds-ground { padding: 20px; border: 1px dashed var(--blaetter-color-rule-strong); font-family: var(--blaetter-font-sans); font-size: 15px; }
  .ds-ground h4 { margin: 0 0 12px; font-size: 15px; }
  .ds-notice { padding: 12px 16px; border-left: 5px solid; }
  .ds-footer { margin: 64px 0 32px; padding-top: 12px; border-top: 1px solid var(--blaetter-color-rule); font-family: var(--blaetter-font-sans); font-size: 14px; color: var(--blaetter-color-muted); }

  /* Dark theme: the black claim of the logo inverted, as archiv-dark.css does */
  @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .brand .claim { filter: invert(1); } }
  :root[data-theme="dark"] .brand .claim { filter: invert(1); }

  /* Sticky header and section headings (with the script), as at the end of archiv.css */
  @supports (position: sticky) {
    html { scroll-padding-top: 100px; }
    .header-sticky .site-header { position: sticky; top: 0; z-index: 10; }
    .sticky-below-header { position: sticky; top: 0; z-index: 2; }
  }

  /* Phones: the step phone of the tokens (archiv.css still uses 760px) */
  @media (max-width: 45rem) {
    body { font-size: 17px; }
    .wrap { padding: 0 16px; }
    .nav { float: none; clear: both; }
    .nav li { margin: 0 16px 0 0; line-height: 2; }
    .js .menu-toggle { display: block; float: right; width: 44px; height: 44px; margin: -1px -10px 0 0; padding: 0; border: 0; background: transparent; cursor: pointer; }
    .theme-toggle, .header-compact .theme-toggle { width: 44px; height: 44px; margin: -1px 0 0 0; }
    .js .nav { display: none; }
    .js .nav-open .nav { display: block; padding-top: 10px; }
    .js .nav li { display: block; margin: 0; border-top: 1px solid var(--blaetter-color-rule); line-height: 1.5; }
    .js .nav a { display: block; padding: var(--blaetter-space-s) 0; }
    .page-head .heading { font-size: 22px; }
    .heading { font-size: 19px; line-height: 24px; }
    .ds-toc { columns: 1; }
    .ds-swatches { grid-template-columns: 1fr 1fr; gap: 18px 4%; }
    .ds-grounds { grid-template-columns: 1fr; }
  }
CSS;

    // Before the page renders: the stored theme (no flash) and the class "js".
    $headScript = <<<'JS'
  (function () {
    var root = document.documentElement, theme = '';
    root.className += ' js';
    try { theme = localStorage.getItem('blaetter-design-theme') || ''; } catch (e) {}
    if (theme) { root.setAttribute('data-theme', theme); }
  }());
JS;

    // Modules 1, 8 and 9 of the archive's archiv.js: phone menu, header that sticks and
    // gets smaller, light or dark (here by data-theme, which tokens-dark.css reads).
    $script = <<<'JS'
  (function () {
    var header = document.querySelector('.site-header');
    var button = document.getElementById('menu-toggle');
    if (!header || !button) { return; }
    function setOpen(open) {
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      header.className = header.className.replace(/(^|\s)nav-open(\s|$)/g, ' ').replace(/^\s+|\s+$/g, '') + (open ? ' nav-open' : '');
    }
    button.addEventListener('click', function () { setOpen(button.getAttribute('aria-expanded') !== 'true'); });
    document.addEventListener('keydown', function (ev) {
      if ((ev.key === 'Escape' || ev.key === 'Esc') && button.getAttribute('aria-expanded') === 'true') { setOpen(false); button.focus(); }
    });
    header.addEventListener('click', function (ev) { if (ev.target.closest && ev.target.closest('.nav a')) { setOpen(false); } });
  }());

  (function () {
    var header = document.querySelector('.site-header');
    var root = document.documentElement;
    if (!header) { return; }
    root.className += ' header-sticky';
    var offsets = document.createElement('style');
    document.head.appendChild(offsets);
    var compact = false, full = 0, small = 0;
    function place(height) {
      offsets.textContent = '@supports (position: sticky) { html.header-sticky { scroll-padding-top: ' + (height + 100) + 'px; }'
        + ' .header-sticky .sticky-below-header { top: ' + height + 'px; } }';
    }
    function setClass(on) {
      root.className = root.className.replace(/(^|\s)header-compact(?=\s|$)/g, '') + (on ? ' header-compact' : '');
    }
    function measure() {
      root.className += ' header-measure';
      setClass(false); full = header.offsetHeight;
      setClass(true); small = header.offsetHeight;
      setClass(compact);
      header.getBoundingClientRect();
      root.className = root.className.replace(/(^|\s)header-measure(?=\s|$)/g, '');
    }
    function setCompact(on) {
      compact = on;
      setClass(on);
      header.style.marginBottom = on ? (full - small) + 'px' : '0px';
      place(on ? small : full);
    }
    function update() {
      var y = window.pageYOffset || root.scrollTop;
      if (compact ? y < 20 : y > 50) { setCompact(!compact); }
    }
    window.addEventListener('scroll', update);
    var width = window.innerWidth;
    window.addEventListener('resize', function () {
      if (window.innerWidth === width) { return; }
      width = window.innerWidth;
      measure();
      setCompact(compact);
    });
    measure();
    place(full);
    update();
    window.addEventListener('load', update);
  }());

  (function () {
    var button = document.getElementById('theme-toggle');
    if (!button || !window.matchMedia) { return; }
    var root = document.documentElement, key = 'blaetter-design-theme';
    var system = window.matchMedia('(prefers-color-scheme: dark)');
    var choice = root.getAttribute('data-theme') || '';
    function dark() { return choice ? choice === 'dark' : system.matches; }
    function show() {
      var label = dark() ? 'Zur hellen Darstellung wechseln' : 'Zur dunklen Darstellung wechseln';
      button.title = label;
      button.querySelector('.visually-hidden').textContent = label;
    }
    button.addEventListener('click', function () {
      var next = !dark();
      choice = next === system.matches ? '' : (next ? 'dark' : 'light');
      try { if (choice) { localStorage.setItem(key, choice); } else { localStorage.removeItem(key); } } catch (e) {}
      if (choice) { root.setAttribute('data-theme', choice); } else { root.removeAttribute('data-theme'); }
      show();
    });
    if (system.addEventListener) { system.addEventListener('change', show); }
    show();
    button.removeAttribute('hidden');
  }());
JS;

    return <<<HTML
<!DOCTYPE html>
<!-- Generated from tokens.json (blaetter-design) by bin/tokens.php, do not edit. -->
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Design tokens – »Blätter«</title>
<link rel="icon" href="../assets/img/logo_palm.svg" type="image/svg+xml">
<link rel="stylesheet" href="tokens.css">
<link rel="stylesheet" href="tokens-dark.css">
<script>
$headScript
</script>
<style>
$css
</style>
</head>
<body>
<a class="skip" href="#inhalt">Skip to content</a>

<header class="site-header">
  <div class="wrap">
    <a class="brand" href="#inhalt" title="Zum Anfang">
      <img class="palm" src="../assets/img/logo_palm.svg" width="40" height="40" alt=""><img class="claim" src="../assets/img/logo_blaetter_claim.svg" width="120" height="26" alt="Blätter für deutsche und internationale Politik">
    </a>
    <button type="button" class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-controls="hauptnavigation">
      <span class="menu-icon" aria-hidden="true"></span><span class="visually-hidden">Menü</span>
    </button>
    <button type="button" class="theme-toggle" id="theme-toggle" title="Zur dunklen Darstellung wechseln" hidden>
      <span class="theme-icon" aria-hidden="true"></span><span class="visually-hidden">Zur dunklen Darstellung wechseln</span>
    </button>
    <nav class="nav" id="hauptnavigation" aria-label="Hauptnavigation">
    <ul>
      <li class="active"><a aria-current="page" href="#uebersicht">Overview</a></li>
      <li><a href="#colours">Colours</a></li>
      <li><a href="#spacing">Spacing</a></li>
      <li><a href="#contrast">Contrast</a></li>
      <li><a href="#rules">Rules</a></li>
    </ul>
    </nav>
  </div>
</header>

<main id="inhalt" class="wrap">

<div class="page-head" id="uebersicht">
  <h1 class="heading"><span class="heading-label">Design</span> Tokens</h1>
  <div class="lede ds-intro">
    <p>Colours, fonts, spacing and breakpoints shared by the »Blätter« layouts: the website (Palm, blaetter-theme), the offline archive and the status page. Generated from <code>tokens.json</code> by <code>./design build</code>, like every other file in <code>dist/</code>; <code>./design check</code> fails when this page is out of date. The elements built from these tokens are shown in the styleguides of the consumers: the archive's <code>docs/design-system.html</code>, the Patternlab styleguide of blaetter-theme and the Storybook of Palm.</p>
  </div>
<ul class="ds-toc">
  <li><a href="#colours">1 Colours</a></li>
  <li><a href="#dark">2 Dark theme</a></li>
  <li><a href="#covers">3 Cover hues</a></li>
  <li><a href="#type">4 Typography</a></li>
  <li><a href="#spacing">5 Spacing</a></li>
  <li><a href="#breakpoints">6 Breakpoints</a></li>
  <li><a href="#contrast">7 Contrast</a></li>
  <li><a href="#rules">8 Shared rules</a></li>
</ul>
</div>

<section class="ds-section" id="colours" aria-labelledby="colours-h">
  <h2 class="divider sticky-below-header" id="colours-h">1 Colours</h2>
  <p class="ds-note">One entry per role. The code line names the custom property the website theme uses (<code>--palm-color-*</code>; every colour is also written as <code>--blaetter-color-&lt;role&gt;</code>). Not part of the dark theme, used on the website only: $websiteOnlyList.</p>
  <ul class="ds-swatches">
$light  </ul>
</section>

<section class="ds-section" id="dark" aria-labelledby="dark-h">
  <h2 class="divider sticky-below-header" id="dark-h">2 Dark theme</h2>
  <p class="ds-note">Dark value of each role, as <code>tokens-dark.css</code> sets it (archive and status page; the website has no dark theme yet). Where a role keeps another colour as text, background or border, that variant is listed: the archive maps every kind on its own, custom properties carry the default.</p>
  <ul class="ds-swatches">
$dark  </ul>
</section>

<section class="ds-section" id="covers" aria-labelledby="covers-h">
  <h2 class="divider sticky-below-header" id="covers-h">3 Cover hues</h2>
  <p class="ds-note">The hues of the covers since the print layout of 2026; the palette stays over the years, the issues rotate through it. <strong>Assumed values</strong> until the designer's sRGB values for the Pantone U colours are in. Only for bars, dots and large figures (3:1 on the page); never as running text. Dark values: mixed with 20&nbsp;% white.</p>
  <ul class="ds-swatches">
$cover  </ul>
</section>

<section class="ds-section" id="type" aria-labelledby="type-h">
  <h2 class="divider sticky-below-header" id="type-h">4 Typography</h2>
  <p class="ds-note">The font stacks. The web fonts themselves live in the consumers; this page shows the fallback when they are not installed.</p>
  <div class="ds-scroll"><table class="ds-table">
    <tr><th>Stack</th><th>Use</th><th>Sample</th></tr>
$fonts  </table></div>
</section>

<section class="ds-section" id="spacing" aria-labelledby="spacing-h">
  <h2 class="divider sticky-below-header" id="spacing-h">5 Spacing</h2>
  <p class="ds-note">Margins, paddings and gaps from one scale of 4&nbsp;px (written as rem, px / 16). New components use only these steps.</p>
  <div class="ds-scroll"><table class="ds-table">
    <tr><th>Name</th><th>Width</th><th>Bar</th><th>For</th></tr>
$spacing  </table></div>
</section>

<section class="ds-section" id="breakpoints" aria-labelledby="breakpoints-h">
  <h2 class="divider sticky-below-header" id="breakpoints-h">6 Breakpoints</h2>
  <p class="ds-note">Three steps for all layouts. Media queries cannot read custom properties, so CSS writes these queries out literally; <code>--blaetter-media-*</code> documents them and serves scripts.</p>
  <div class="ds-scroll"><table class="ds-table">
    <tr><th>Name</th><th>Media query</th><th>Width</th><th>For</th></tr>
$breakpoints  </table></div>
</section>

<section class="ds-section" id="contrast" aria-labelledby="contrast-h">
  <h2 class="divider sticky-below-header" id="contrast-h">7 Contrast</h2>
  <p class="ds-note">Every pair of <code>contrast.pairs</code> in both themes ($summary). WCAG 2: 4.5:1 for text, 3:1 for large text, focus outlines and borders of controls. Ratios are rounded down, so 4.49 never passes as 4.5. Each sample shows the pair in its own colours, whatever theme this page is in.</p>
  <div class="ds-scroll"><table class="ds-table">
    <tr><th>Sample</th><th>Theme</th><th>Foreground</th><th>Background</th><th class="ds-num">Ratio</th><th class="ds-num">Needs</th><th></th></tr>
$contrast  </table></div>
</section>

<section class="ds-section" id="rules" aria-labelledby="rules-h">
  <h2 class="divider sticky-below-header" id="rules-h">8 Shared rules</h2>
  <div class="ds-item">
    <h3>Grounds: the website differs</h3>
    <p class="ds-note">The archive and the status page lie on white (<code>page</code>); grey (<code>surface</code>) marks a tile, a notice or a hover row on it. The website still lies on grey: its page wrapper is <code>surface</code>. An element that is grey on white elsewhere would vanish there, so on the website it turns around: white with a frame. Moving the website onto white is a later, larger step.</p>
    <div class="ds-grounds">
      <div class="ds-ground" style="background:$page;color:$text"><h4>Archive, status page: on white</h4><div class="ds-notice" style="background:$surface;border-color:$muted">Notice: grey, rule at the left.</div></div>
      <div class="ds-ground" style="background:$surface;color:$text"><h4>Website: on grey</h4><div class="ds-notice" style="background:$page;border:1px solid $success;border-left:5px solid $success">Message: white, framed in its colour.</div></div>
    </div>
  </div>
  <div class="ds-item">
    <h3>Rules for every layout</h3>
    <ul class="ds-note">
      <li>Colours, fonts, spacing and breakpoints come only from <code>tokens.json</code>; consumers keep verbatim copies and check them (see README). A new value starts here, never in a consumer.</li>
      <li>Text reaches 4.5:1 on its ground, focus outlines and borders of controls 3:1; a new pairing gets an entry in <code>contrast.pairs</code>.</li>
      <li>Grey text is <code>muted</code>; <code>boundary</code> is for borders of controls only, never for text.</li>
      <li>Focus is always visible: a solid outline or ring in <code>focus</code> (3:1 on its ground), no soft glow.</li>
      <li>Colour is never the only signal: a cover hue or a status colour always comes with a word, an icon or a shape.</li>
      <li>Cover hues are for bars, dots and large figures, not for running text or links.</li>
      <li>Spacing uses the steps of the scale, media queries the three breakpoints; the website's legacy styles move onto them when their part is rebuilt.</li>
    </ul>
  </div>
</section>

<p class="ds-footer">Generated from <code>tokens.json</code>. Change the tokens there, then <code>./design build</code> and <code>./design copies --update</code>.</p>
</main>
<script>
$script
</script>
</body>
</html>

HTML;
}
