CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Font Discovery
 * Local Fonts
 * Google Fonts
 * Google Fonts Locally


INTRODUCTION
------------

Provide functionality for both adding and managing fonts.


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


LOCAL FONTS
-----

A local font definition looks as follows. The 'faces.weight', 'faces.display'
and 'faces.unicode' properties are options. If faces.display is not set, 'swap'
will be used. The 'generic' property should be set to one of the generic font
definitions provided by this module. These are 'sans', 'serif' and 'mono'. You
can also use 'cursive' if you are defining a script font.

```yml
inter:
  family: Inter
  type: local
  generic: sans
  faces:
    -
      style: "italic"
      weight: "100 900"
      display: "swap"
      src: "fonts/Inter/Inter-cyrillic-italic.woff2"
      format: "woff2"
      unicode: "U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116"
    -
      style: "italic"
      weight: "100 900"
      display: "swap"
      src: "fonts/Inter/Inter-greek-ext-italic.woff2"
      format: "woff2"
      unicode: "U+1F00-1FFF"
```


GOOGLE FONTS
-----

Google fonts can be used. The 'spec' property can be found on the Google Fonts
site when selecting a font.

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
serve those fonts locally. This avoids additional javascript overhead.

The following website helps to extract the CSS and font files:

https://variable-font-helper.web.app/
