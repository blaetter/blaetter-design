# blaetter-design

The design tokens of the »Blätter« layouts: colours (light and dark), the
hues of the covers and the font stacks. `tokens.json` is the only place
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

**contrast.pairs**: foreground and background that must reach `min` (WCAG 2
contrast ratio) in both themes; `cover.*` stands for all cover hues. The
foreground counts as text unless `"as": "border"` is given (focus outlines,
borders of fields). The result of every pair is listed in
`dist/contrast.md`; ratios are rounded down, so 4.49 never passes as 4.5.

## Generated files

- `dist/tokens.css`: light values as `--blaetter-color-*`,
  `--blaetter-cover-*`, `--blaetter-font-*`, plus the `--palm-color-*`
  names of the website theme pointing to them.
- `dist/tokens.scss`: light values and font stacks as Sass variables
  (`$blaetter-color-*`, `$blaetter-cover-*`, `$blaetter-font-*`) for the Sass
  build of blaetter-theme.
- `dist/tokens-dark.css`: dark values of the same properties. They apply
  when the system is dark and the page has no `data-theme="light"`, or
  when it has `data-theme="dark"`. Pages without a dark theme do not load it.
- `dist/archive.json`: `colors` (light hex per role), `darkMap` (light hex
  → dark hex as text, background, border; `null` keeps the colour) and
  `cover` (light and dark hue).
- `dist/contrast.md`: every checked pair with its ratio.
