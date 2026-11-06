<?php

declare(strict_types=1);

/*
 * Reads tokens.json, checks it (format, contrast of every listed pair in both
 * themes) and writes the files in dist/:
 *
 *   tokens.css       light values as custom properties (--blaetter-*), plus
 *                    the --palm-color-* names the website theme uses
 *   tokens-dark.css  dark values of the same properties, for pages with a dark theme
 *   archive.json     plain hex values and the light → dark map of the offline archive
 *   contrast.md      every checked pair with its contrast, for review
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
        'archive.json' => archiveJson($model),
        'contrast.md' => contrastMd($model),
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

    return ['colors' => $colors, 'fonts' => $fonts, 'pairs' => $pairs];
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
        $stack = array_map(static fn (string $s): string => in_array($s, ['serif', 'sans-serif', 'monospace'], true) ? $s : '"' . $s . '"', $f['stack']);
        $lines[] = sprintf('  --blaetter-font-%s: %s;', $name, implode(', ', $stack));
    }
    $lines[] = '';
    $lines[] = '  /* Names of the website theme (palm) */';
    foreach ($model['colors'] as $name => $c) {
        if ($c['palm'] !== null) {
            $lines[] = sprintf('  --palm-color-%s: var(%s);', $c['palm'], property($name));
        }
    }

    return banner('Light values.') . ":root {\n" . implode("\n", $lines) . "\n}\n";
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

    return json_encode(['$comment' => 'Generated from tokens.json (blaetter-design), do not edit.', 'colors' => $colors, 'darkMap' => $map, 'cover' => $cover], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

function contrastMd(array $model): string
{
    $out = "<!-- Generated from tokens.json, do not edit. -->\n# Kontraste\n\n| Theme | Vordergrund | | Hintergrund | | Kontrast | Mindestens | |\n|---|---|---|---|---|---:|---:|---|\n";
    foreach (contrastRows($model) as [$theme, $fg, $fgHex, $bg, $bgHex, $ratio, $min]) {
        $out .= sprintf("| %s | %s | `%s` | %s | `%s` | %.2f:1 | %s:1 | %s |\n", $theme, $fg, $fgHex, $bg, $bgHex, floor($ratio * 100) / 100, $min, floor($ratio * 100) / 100 >= $min ? 'ok' : '**zu niedrig**');
    }

    return $out;
}
