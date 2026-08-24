---
name: neo-font-dev
description: Understand and modify the neo_font MODULE internals (PHP) — the YAML font discovery (*.neo.font.yml), the Font plugin manager/plugin (family/type/generic/selector/faces), the five font roles (primary/secondary/accent/heading/ui) and their settings form, and the two NeoBuild event subscribers that emit `.font-{selector}` utilities, `font-{role}` tokens, `--font-{role}-family` vars and `@font-face` rules. Use when editing files under web/modules/contrib/neo_font/src, registering a local/Google font, adding a font role, or debugging why a font utility or @font-face won't resolve. NOT for the Vite/Tailwind pipeline itself (use neo-build) or authoring components / using font utilities in twig (use neo-component).
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Developing the neo_font module

This skill is for working on **neo_font's own code** and on **font definitions**.
If you're just applying a font utility in a component, that's **neo-component**.
If you're debugging the Vite/Tailwind build itself, that's **neo-build**. neo_font
sits *on top of* neo-build: it feeds font data into the same event pipeline
neo_color uses ([[neo-color-dev]] is the closest sibling — read it for the event
mechanics).

## Mental model

Fonts flow through three stages: **discover → resolve → emit**.

- **Discover** — a `MODULE_OR_THEME.neo.font.yml` file at the *root* of any enabled
  module or theme declares one or more fonts. `FontPluginManager` finds them with a
  `YamlDiscovery` on the `neo.font` key (so `neo_font.neo.font.yml`,
  `mytheme.neo.font.yml`, …). This is a **YAML plugin** system, not config entities —
  there is no admin CRUD for fonts, only for which font fills each role.
- **Resolve** — `processDefinition()` checks + normalises each font (id `_`→`-`,
  defaults `label`/`selector`, resolves local `src` paths). It **throws nothing**:
  every check returns a `FontDeclarationProblem`, which is logged and — for a
  refusal — drops that font from the set (see Gotchas). `findDefinitions()` then
  rewrites each surviving font's `generic` from a *generic id* into that generic's
  raw CSS fallback stack.
- **Emit** — two `neo_build` event subscribers turn the definitions into CSS: build-
  time Tailwind `fontFamily` entries **and** runtime inline `.font-*` rules /
  `@font-face` / CSS vars. A separate `hook_page_attachments` adds Google CDN links.

There are two distinct axes, and confusing them is the #1 mistake:

| Axis | What it is | Utility | Baked where |
|---|---|---|---|
| **selector** | one *specific* font, `selector` defaults to the id | `.font-{selector}` (e.g. `font-inter`) | build **and** inline |
| **role** | a *semantic slot* config points at a font | `font-{role}` → `var(--font-{role}-family)` | token baked at build, value set at runtime |

The five roles are `primary secondary accent heading ui`
(`FontPluginManager::getSettingTypes()`). `font-heading` resolves to whatever font
the config assigns to the heading role — **swappable at runtime without a rebuild**,
because the utility is indirected through `--font-heading-family` (set by the inline
subscriber, cache-tagged on `config:neo_font.settings`). A concrete `.font-inter` is
the raw family list, baked into the build.

## The font definition (`*.neo.font.yml`)

```yml
inter:                 # machine id → utility `.font-inter`, class scope `-`→ id
  family: Inter        # REQUIRED — the CSS family name
  type: local          # REQUIRED — local | google | generic
  generic: sans        # a generic id (local/google) OR a raw fallback stack (generic)
  selector: brand      # optional — the `.font-{selector}` class; defaults to id.
                       # NEVER a role name — that is reported (see Gotchas)
  faces: […]           # REQUIRED for type: local (see below)
  spec: 'ital,wght@…'  # for type: google — the Google `css2` spec string
```

Per-face keys (`type: local` only):

| Key | Meaning | Notes |
|---|---|---|
| `src` | **REQUIRED** path relative to the declaring module/theme | validated to exist; rewritten to `base_path() . src` |
| `format` | e.g. `woff2`, `truetype` | appended as `format('…')` |
| `weight` | e.g. `400` or a variable range `100 900` | |
| `style` | `normal` / `italic` | |
| `unicode` | `unicode-range` value | faces sharing family+weight+style+display+range are merged (multi-format) |
| `ascent-override` / `descent-override` / `line-gap-override` | metric overrides | passed through verbatim |
| `display` | value for `font-display` | defaults to `swap`; the legacy `swap` key is still accepted as a fallback |

## Types & generic resolution

- **`generic`** fonts (`sans serif mono cursive` in `neo_font.neo.font.yml`) are the
  fallback stacks. For a generic, `generic:` holds the *raw CSS stack*
  (`ui-sans-serif, system-ui, …`) and processing returns early (no faces, no path
  resolution).
- **`local`** / **`google`** fonts set `generic:` to a generic **id**.
  `findDefinitions()` swaps that id for the generic's stack, so the final font-family
  becomes `'Family', <resolved stack>` (`FontDefault::getPropertyValue()`). Point a
  new script font at `cursive`, body/UI fonts at `sans`, etc.
- **`local`** requires `faces` and every `src` must exist on disk at
  `{provider path}/{src}` — a missing file is a **refusal**: that one font is logged
  and dropped at discovery, and the next `drush neo:build` fails.
- **`google`** contributes to the CDN `<link>` (see below) and needs no faces.

## Where things live (`src/`)

- `FontPluginManager.php` — discovery + checking. `getSupportedTypes()`
  (local/google/generic), `getSettingTypes()` (the 5 roles), `getGoogleUrl()` (builds
  the `fonts.googleapis.com/css2?family=…&display=swap` URL), `processDefinitionLocal()`
  (path resolution + existence check), and the `findDefinitions()` generic-flattening.
  `findDeclarationProblems()` is the **single pass every check lives in** — it returns
  problems instead of throwing, so `processDefinition()` (log + drop) and
  `checkDeclarations()` (collect for prepare) can never disagree about what is wrong
  with a file. `checkDeclarations()` re-reads the declaration **files**, never the
  cached set: a refusal removes the definition, so a cache-served set would report a
  clean site. `alterDefinitions()` is where a dropped font actually leaves the set —
  before `hook_neo_font_info`, so an alter never sees a font that doesn't exist.
  Local fonts are served straight from the declaring extension's path via
  `base_path()` — nothing is copied anywhere, so the manager owns no font directory.
- `FontDefault.php` (`FontInterface`) — one font instance. `getPropertyValue()` (the
  `'Family', generic` CSS value), `getFontFaces()` (assembles `@font-face` descriptor
  arrays, merging same-key faces across formats), `preview()` (the weight-ramp render
  array used by the settings form).
- `Settings/FontSettings.php` — the `neo_settings` plugin at
  `/admin/config/neo/font` (permission `administer neo_font`). Renders a preview table
  per type and a `<select>` per role → this is the **only** UI; it writes
  `neo_font.settings` (role → font id).
- `FontDeclarationProblem.php` / `FontDeclarationSeverity.php` — one thing wrong with
  one declaration: the extension, the font key as the YAML spells it, a message template
  + context (so a logger records placeholders separately), and a severity. Built through
  `::refusal()` / `::report()` — the constructor is private so picking a severity is
  deliberate at every call site. `render()` is for the caller that needs a string.
- `EventSubscriber/NeoBuildDeclarationEventSubscriber.php` (`onBuild`, priority **1000**)
  — the prepare-time refusal. Holds the plugin manager and nothing else, runs ahead of
  the two emitting subscribers, and throws one `\InvalidArgumentException` listing
  **every** refusal (not the first) when `checkDeclarations()` finds any. Deliberately a
  separate class, so the emitting subscribers keep injecting only the role resolver.
- `EventSubscriber/NeoBuildEventSubscriber.php` (`onBuild`) — registers Tailwind
  `fontFamily[selector]` (concrete families) and `fontFamily[role] =
  var(--font-{role}-family)` for the configured font of each role. Feeds the **build**.
- `EventSubscriber/NeoBuildInlineEventSubscriber.php` (`onInlineBuild`) — injects at
  **runtime**: `.font-{selector}{font-family:…}`, `--font-{role}-family` for each
  configured role, and one `@font-face` per local face. Adds cache tag
  `config:neo_font.settings`.
- `neo_font.module` — `hook_page_attachments()` only: if any `google` font exists,
  adds the CDN stylesheet `<link>` + `preconnect` hints to googleapis/gstatic.

## Rebuild rules (mirror neo-color)

- **Changed which font a role uses** (the settings form / `neo_font.settings`) →
  `drush cr`. The inline subscriber re-emits `--font-{role}-family` and the cache tag
  invalidates. **No build needed** — that's the whole point of the role indirection.
- **Added/edited a font, a new `.font-{selector}`, or the `onBuild` subscriber** →
  `drush cr` then `drush neo:build front` **and** `drush neo:build back` (or
  `npm run deploy`). Tailwind emits `font-*` utilities on demand per scope; a utility
  referenced only in `front` won't exist in the `back` (admin) build. Admin pages
  render in **back** — forgetting it leaves the admin font stale.
- **Edited this skill** → source is
  `neo_font/install/skills/neo-font-dev/SKILL.md`; the active copy at
  `.claude/skills/neo-font-dev/SKILL.md` is regenerated by `drush neo:build:install`.
  Edit the source and keep the two identical.

## Gotchas

- **A bad declaration costs the font, not the site.** A problem the font *cannot*
  survive is a **font declaration refusal** — logged at error and dropped at discovery,
  and fatal at prepare; a problem the font *does* survive is a **font declaration
  report** — logged at warning, and prepare succeeds. That one rule decides every
  check, existing and next; nothing in this module throws at discovery, because the
  Google font link resolves the whole definition set inside `hook_page_attachments`, so
  a throw there is a stack trace on every page render of every site. Refusals today:
  no `family`, no `type`, an unsupported `type`, a derived id equal to a role name,
  and (local) an unresolvable provider, no `faces`, a face with no `src`, a `src` not
  on disk. Reports today: the selector–role collision below, and only it.
- **`font-display` comes from the `display` face key.** `FontDefault::getFontFaces()`
  reads `$face['display'] ?? $face['swap'] ?? 'swap'` — the documented `display:` key
  wins, the legacy `swap:` key is a fallback, and the default is `swap`. (Historically
  the accessor read only `swap`, so `display:` was silently ignored — fixed.)
- **A `selector` equal to a role name is reported.** `onBuild` writes both
  `fontFamily[selector]` and `fontFamily[role]` into the same map, so a font whose
  selector is e.g. `ui` writes the same key as the `ui` role and one of the two is
  silently lost. `processDefinition()` logs a warning on the injected `neo_font` logger
  channel naming the font, the selector and the role — right beside the id guard, which
  is a refusal. It **reports rather than refuses on purpose**: the refusal lands in a
  later release, so a site already carrying a colliding selector gets one version that
  warns it before one that drops the font. Only an explicitly declared
  `selector:` can trip it — an undeclared selector takes the id, and an id equal to a
  role name is refused outright. The message deliberately doesn't say which entry wins;
  that is the role resolver's subject. No shipped example declares one.
- **Fonts are a cache-backed YAML plugin.** Adding/removing a `*.neo.font.yml` entry or
  a font file needs `drush cr` before it's discovered. Discovery is cached, so a dropped
  font is logged **once per cache rebuild**, not once per request — a site whose cache
  has been warm for a week has one old log line and a missing font. Prepare is the half
  that tells the author now.
- **Google via CDN vs local.** `type: google` pulls from the CDN at page-attach time
  (extra request + no `@font-face` control). The README's recommended path is to
  download the font and declare it as `type: local` instead — prefer that.
- **Generic mismatch is silent.** If `generic:` on a local/google font names a generic
  id that doesn't exist, `findDefinitions()` leaves the raw id in place and it ends up
  in the CSS `font-family` verbatim. Check the generic exists in some `*.neo.font.yml`.

## Introspect instead of tracing the pipeline

Dump exactly what a font emits before rebuilding:

```php
// _audit.php in the project root (drush must be able to read it).
$m = \Drupal::service('plugin.manager.neo_font');
$defs = $m->getDefinitions();                 // every discovered font, post-resolve
$inter = $m->createInstance('inter');
$val   = $inter->getPropertyValue();          // the "'Inter', ui-sans-serif, …" value
$faces = $inter->getFontFaces();              // the @font-face descriptor arrays
$url   = $m->getGoogleUrl();                  // the CDN link (or NULL)
```

`drush php:script ./_audit.php` then `rm` it. This resolves the generic flattening and
role indirection for you rather than reading `findDefinitions()`/`onBuild` by hand.
