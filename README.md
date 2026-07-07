CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Font Discovery
 * Generic Fonts
 * Local Fonts
 * Google Fonts
 * Google Fonts Locally
 * Font Roles
 * Using Fonts
 * Build Integration


INTRODUCTION
------------

Provide functionality for both adding and managing fonts.

Fonts are declared in YAML by any module or theme, resolved into a plugin, and
emitted as CSS through the Neo build pipeline. Each font is exposed as a
`.font-{selector}` utility, and five semantic "roles" (primary, secondary,
accent, heading, ui) can be pointed at any declared font from the admin UI.


REQUIREMENTS
------------

This module requires Neo.


INSTALLATION
------------

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/node/1897420 for further information.


FONT DISCOVERY
--------------

Modules and themes can specify font definitions via a
MODULE_THEME_NAME.neo.font.yml file placed in the root of the module/theme.
Definitions are discovered and cached, so run `drush cr` after adding, editing,
or removing one.

Every definition supports these top-level properties:

 * family    (required) The CSS font family name, e.g. "Inter".
 * type      (required) One of 'local', 'google' or 'generic'.
 * generic   For 'local'/'google', the id of a generic font whose stack is used
             as the fallback (e.g. 'sans'). For 'generic', the raw CSS fallback
             stack itself.
 * selector  The class suffix for the generated `.font-{selector}` utility.
             Defaults to the machine id.
 * faces     (required for 'local') The @font-face definitions (see below).
 * spec      (for 'google') The Google Fonts `css2` spec string.


GENERIC FONTS
-------------

Generic fonts define the fallback stacks that local and Google fonts point at
via their 'generic' property. This module ships 'sans', 'serif', 'mono' and
'cursive'. Use 'cursive' when defining a script font. A generic definition sets
'generic' to the raw CSS stack rather than to another font id.

```yml
sans:
  family: Sans-Serif
  type: generic
  generic: "ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'"
```


LOCAL FONTS
-----------

A local font definition looks as follows. The 'faces.weight', 'faces.style'
and 'faces.unicode' properties are optional. Each 'faces.src' is resolved
relative to the declaring module/theme and must exist on disk — a missing file
throws an error during discovery. The 'generic' property should be set to one of
the generic font ids ('sans', 'serif', 'mono' or 'cursive').

Additional optional face keys: 'format' (e.g. "woff2"), 'ascent-override',
'descent-override' and 'line-gap-override'. `font-display` defaults to 'swap';
to override it set a 'display' key on the face.

```yml
inter:
  family: Inter
  type: local
  generic: sans
  faces:
    -
      style: "italic"
      weight: "100 900"
      src: "fonts/Inter/Inter-cyrillic-italic.woff2"
      format: "woff2"
      unicode: "U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116"
    -
      style: "italic"
      weight: "100 900"
      src: "fonts/Inter/Inter-greek-ext-italic.woff2"
      format: "woff2"
      unicode: "U+1F00-1FFF"
```


GOOGLE FONTS
------------

Google fonts can be used. The 'spec' property can be found on the Google Fonts
site when selecting a font. A Google font is loaded from the Google CDN via a
stylesheet link added to the page head (with preconnect hints).

Please see [Google Fonts Locally](#google-fonts-locally) for a better way.

```yml
inter:
  family: Inter
  type: google
  generic: sans
  selector: ui
  spec: 'ital,opsz,wght@0,14..32,100..900;1,14..32,100..900'
```


## GOOGLE FONTS LOCALLY

Although Google fonts can be loaded via CDN, the recommended approach is to
serve those fonts locally. This avoids the extra request and gives full control
over the @font-face declarations. Download the font, place the files in your
module/theme, and declare it as a `type: local` font.

The following website helps to extract the CSS and font files:

https://variable-font-helper.web.app/


## FONT ROLES

Five semantic roles are provided: primary, secondary, accent, heading and ui.
Each role is mapped to a declared font on the settings page at
Administration » Configuration » Neo » Fonts (`/admin/config/neo/font`,
permission "administer neo_font"). The mapping is stored in `neo_font.settings`.

Roles let a theme reference an intent ("the heading font") rather than a
specific font, so the underlying font can be swapped from the admin UI without
touching templates or rebuilding assets.


## USING FONTS

Each declared font produces a `.font-{selector}` utility bound to that specific
font (e.g. `.font-inter`). Each role produces a `font-{role}` utility
(`font-primary`, `font-heading`, `font-ui`, …) that resolves to whichever font
is currently assigned to that role via a CSS variable (`--font-{role}-family`).

Prefer role utilities in components so fonts stay swappable:

```html
<h1 class="font-heading">…</h1>
<body class="font-ui">…</body>
```

Use a `.font-{selector}` utility only when you need one specific font
regardless of the role configuration.


## BUILD INTEGRATION

Fonts are emitted through the Neo build (see the neo_build module):

 * Font family utilities and role tokens are registered at build time, so
   adding a new font or a new `.font-{selector}` requires rebuilding the Neo
   assets (both the front and back scopes).
 * `@font-face` rules and the `--font-{role}-family` variables are injected
   inline at runtime and are cache-tagged on `neo_font.settings`, so changing
   which font a role uses only requires a cache rebuild (`drush cr`) — no asset
   rebuild.
