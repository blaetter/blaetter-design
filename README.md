# blaetter-design

The design tokens of the »Blätter« layouts: colours (light and dark), the
hues of the covers and the font stacks. `tokens.json` is the only place
where they are defined; everything else is generated from it or checked
against it:

| Consumer | Uses | Checked by |
|---|---|---|
| Website theme (palm) | `dist/tokens.css` (`--palm-color-*` names) | a test in the theme comparing its copy with this repository |
| Offline archive (blaetter-archiv) | `dist/archive.json`: hex values for `archiv.css`, the light → dark map for `archiv-dark.css`, the cover hues | a test in the archive |
| Status page (blaetter/status) | `dist/tokens.css`, `dist/tokens-dark.css` | its GitHub Action |

The consumers keep a copy of the files they use, so none of them depends on
this repository at run time.

## Use

```sh
./design build   # write dist/ from tokens.json
./design check   # exit 1 when dist/ is out of date or a contrast pair fails
```

Both run `bin/tokens.php` in Docker (`php:8.4-cli-alpine`); with a local
PHP 8.2 or later, `php bin/tokens.php build` works as well. Commit
`tokens.json` and `dist/` together; the GitHub Action runs `check` on every
push.

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

**cover**: the hues of the covers of the current year. Their dark values
are not set by hand: mixed with 20 % white, as the archive shows them on
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
- `dist/tokens-dark.css`: dark values of the same properties. They apply
  when the system is dark and the page has no `data-theme="light"`, or
  when it has `data-theme="dark"`. Pages without a dark theme do not load it.
- `dist/archive.json`: `colors` (light hex per role), `darkMap` (light hex
  → dark hex as text, background, border; `null` keeps the colour) and
  `cover` (light and dark hue).
- `dist/contrast.md`: every checked pair with its ratio.
