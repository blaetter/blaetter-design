# blaetter-design

The design tokens of the »Blätter« layouts: colours (light and dark), the
hues of the covers, the font stacks, the spacing scale and the breakpoints. `tokens.json` is the only place
where they are defined; everything else is generated from it or checked
against it:

| Consumer | Copy | Checked by |
|---|---|---|
| Website theme (Palm) | `css/blaetter-tokens.css` = `dist/tokens.css` (`--palm-color-*` names); the theme's `css/tokens.css` adds semantic tokens and must not redefine the base colours | `make tokens-check` in the theme (`make tokens` copies) |
| Website styleguide (blaetter-theme) | `source-bundle/scss/00-global/00-blaetter-tokens.scss` = `dist/tokens.scss`; `01-settings.scss` only points its Sass variables to it | `make colors-check` in the theme: every colour of the built `palm.css` is a token or on its short list of exceptions |
| Offline archive (blaetter-archiv) | `config/design-tokens.json` = `dist/archive.json`: hex values for `archiv.css`, the light → dark map for `archiv-dark.css`, the cover hues | `DesignTokensTest` in the archive |
| Status page (blaetter/status) | `assets/css/tokens.css`, `assets/css/tokens-dark.css` | `tests/design-tokens.sh`, run by its workflow *Publish page* against this repository |

The consumers keep a copy of the files they use, so none of them depends on
this repository at run time.

## Use

```sh
./design build   # write dist/ from tokens.json
./design check   # exit 1 when dist/ is out of date or a contrast pair fails
open dist/index.html   # overview of all tokens and contrasts
```

```sh
./design copies            # compare the consumers' copies with dist/
./design copies --update   # copy dist/ to the consumers
```

`copies` looks for the consumers next to this repository (`../blaetter-archiv`,
`../status.blaetter.de`, `../blaetter-theme`, the theme inside `../blaetter.web`; see
`bin/copies.sh`), skips those not checked out and runs on the host. After a
change: `./design build`, `./design copies --update`, then commit in every
consumer.

`build` and `check` run `bin/tokens.php` in Docker (`php:8.4-cli-alpine`); with a local
PHP 8.2 or later, `php bin/tokens.php build` works as well. Commit
`tokens.json` and `dist/` together; the GitHub Action runs `check` on every
push.

## Grounds: the website differs

The archive and the status page lie on white (`page`); grey (`surface`)
marks a tile, a notice or a hover row on it. The website (Palm,
blaetter-theme) still lies on grey: its page wrapper is `surface`
(`$page-background-color` in blaetter-theme). An element that is grey on white elsewhere
would vanish there, so on the website it turns around: white with a frame.
Example: the notice of the archive is grey with a rule at the left; Drupal's
status messages on the website are white, framed in the colour of their
kind, with the broad rule at the left (`css/messages.css` in Palm).
Contrast pairs on `surface` therefore stay in `tokens.json` even where the
website shows the element on white. Moving the website onto white is a
later, larger step.

## Spacing

Margins, paddings and gaps come from one scale of 4 px. It contains the
steps of the website's legacy styles (blaetter-theme), so the website keeps
its rhythm; their names keep the legacy names small, medium, large:

| Name | Width | Legacy (blaetter-theme) | For |
|---|---|---|---|
| `2xs` | 4 px | – | between an icon and its text, small offsets |
| `xs` | 8 px | – | filters and fields stacked in a narrow column, compact controls |
| `s` | 12 px | `$smallGapPx` | padding of boxes and forms |
| `m` | 16 px | `$mediumGapPx` | padding of framed teasers; archive page margin on phones |
| `l` | 20 px | `$largeGapPx`, `$gridGutter` | grid gutter, teaser padding |
| `xl` | 24 px | – | archive page margin, gaps between sections |
| `2xl` | 32 px | – | large gaps between sections |
| `3xl` | 40 px | `$pagePaddingPx` | page padding |
| `block` | 30 px | `$blockBottomMarginPx` | gap below blocks and teasers (outside the scale) |

New components use only these steps. Before the scale (11/2026) the three
layouts used about 30 to 40 different values each (the archive mostly 6, 8,
10, 12, 14 px; the website above all 20 px); odd values move onto the scale
when their part is rebuilt. Values in `em` that grow with the font size
(e.g. paragraph spacing) are not part of the scale.

## Breakpoints

Three steps for all layouts, taken from the website (its legacy styles set
them, rebuilding those would be expensive; 720 px lies close to the 768 px
many frameworks use):

| Name | Media query | Width | For |
|---|---|---|---|
| `phone` | `(max-width: 45rem)` | up to 720 px | phones: one column, compact elements |
| `above-phone` | `(min-width: 45.001rem)` | from 720 px | everything that is not a phone |
| `desktop` | `(min-width: 75rem)` | from 1200 px | laptops and larger screens |
| `wide` | `(min-width: 88.75rem)` | from 1420 px | the website's page width: side columns |

Media queries cannot read custom properties, so CSS writes these queries out
literally; the consumers check that no other width appears in their own
styles. The generated values (`--blaetter-media-*`, `$blaetter-media-*`,
`media` in `archive.json`) document them and serve scripts:
`matchMedia(getComputedStyle(document.documentElement).getPropertyValue('--blaetter-media-wide'))`.
The website's legacy styles keep their finer steps (420, 560 and 1000 px)
until the parts are replaced; new components use only these three.

## tokens.json

**colors**: one entry per role (`page`, `text`, `link`, `muted`, …):

- `light`: the colour, `#RRGGBB`.
- `dark` (optional): the colour in the dark theme. Either one colour, or an
  object with `default` and optionally `text`, `background`, `border` where
  a role keeps another colour in that kind of property. Example: red text
  turns lighter in the dark theme, a red background stays red:
  `{ "default": "#EC465D", "background": "#E51736" }`. Custom properties get
  `default`; the archive maps every kind separately. Tokens without `dark`
  are not part of a dark theme (website-only colours).
- `palm` (optional): name in the website theme, written as
  `--palm-color-<name>`.
- `use`, `note`: what the colour is for, and why it deviates from the
  website, if it does.

Tokens that share a light value must agree on their dark values, because
the archive maps colours by value.

**cover**: the hues of the covers since the print layout of 2026; the
palette stays the same over the years, the issues rotate through it. The
values are assumptions until the designer's sRGB values for the Pantone U
colours are in. Their dark values are not set by hand: mixed with 20 % white, as the archive shows them on
dark pages.

**fonts**: font stacks, written as `--blaetter-font-<name>`.

**breakpoints**: one entry per step with exactly one of `max` (the upper
end of a step, e.g. phones) or `min` (the lower end), a width in whole px,
and `use`. A `max` step also yields `above-<name>` (its width + 0.001rem, as
the website's legacy styles write it, so the two never overlap).

**spacing**: one entry per step: `px` (whole px), `palm` (optional, name
in the website theme, written as `--palm-space-<name>`), `use` and `note`.
A step off the 4 px grid needs a `note` that says why it exists.

**contrast.pairs**: foreground and background that must reach `min` (WCAG 2
contrast ratio) in both themes; `cover.*` stands for all cover hues. The
foreground counts as text unless `"as": "border"` is given (focus outlines,
borders of fields). The result of every pair is listed in
`dist/contrast.md`; ratios are rounded down, so 4.49 never passes as 4.5.

## Generated files

- `dist/tokens.css`: light values as `--blaetter-color-*`,
  `--blaetter-cover-*`, `--blaetter-font-*`, the spacing as
  `--blaetter-space-*`, the breakpoints as `--blaetter-breakpoint-*` (width)
  and `--blaetter-media-*` (media query), plus the `--palm-color-*` and
  `--palm-space-*` names of the website theme pointing to them.
- `dist/tokens.scss`: light values, font stacks, spacing and breakpoints as
  Sass variables (`$blaetter-color-*`, `$blaetter-cover-*`, `$blaetter-font-*`,
  `$blaetter-space-*`, `$blaetter-breakpoint-*`, `$blaetter-media-*` as strings) for the Sass
  build of blaetter-theme.
- `dist/tokens-dark.css`: dark values of the same properties. They apply
  when the system is dark and the page has no `data-theme="light"`, or
  when it has `data-theme="dark"`. Pages without a dark theme do not load it.
- `dist/archive.json`: `colors` (light hex per role), `darkMap` (light hex
  → dark hex as text, background, border; `null` keeps the colour), `cover`
  (light and dark hue), `spacing` (px per step, the archive uses no custom
  properties) and `media` (media query per breakpoint).
- `dist/contrast.md`: every checked pair with its ratio.
- `dist/index.html`: the overview for people, in the style of the archive's
  design system: every colour (light, dark, covers) as a swatch with its
  use, the font stacks with a sample, the spacing scale, the breakpoints,
  every contrast pair as a live sample, and the rules all layouts share
  (grounds, contrast, focus, colour never the only signal). Header (logo,
  navigation, light/dark button, sticky header that gets smaller), headings
  and page width follow the archive's `docs/design-system.html`. It uses
  `tokens.css` and `tokens-dark.css` beside it and the web fonts and logo in
  `assets/` (copies of the archive's `assets/fonts/` and `assets/img/`);
  open it straight from the checkout. It shows tokens only: the elements
  built from them live in the consumers' styleguides.
