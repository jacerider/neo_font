# Changelog

## A bad font declaration costs one font, and refuses the build at prepare

Every way a font declaration could be wrong used to be a plugin exception thrown
out of definition processing, and definition processing runs during discovery.
Discovery runs whenever anything asks for the declared fonts — and this module
asks on **every page render**, because the Google font link is composed from the
full definition set inside `hook_page_attachments()`. So one wrong `src` path in
one theme took the whole site down, on anonymous pages, with a message about a
plugin rather than about fonts; and because the throw aborted the discovery pass
before its cache was written, it happened again on the next request, and every
font every extension declared went with the one that was wrong.

**Discovery no longer refuses anything.** A declaration this module cannot build
a font from is logged at error on the `neo_font` logger channel — naming the
declaring extension, the font key as the YAML spells it, and the reason — and
that one font is then **dropped**. It does not exist for the rest of the
request: no `.font-{selector}` utility, no `@font-face` rule, no entry in the
Google font link, no option on the settings form, and no role mapping for a role
that named it, which is the behaviour a role pointed at an unknown font already
had. Every other font survives the pass untouched, and the page renders. A theme
whose `font-heading` is broken falls back to its generic stack instead of
white-screening.

**Prepare refuses instead.** `drush neo:build` — and so `npm run deploy` — now
runs a font declaration check over the declaration files themselves, gathers
every problem across every extension, and fails the build when any of them means
a font cannot be built. One message lists them all, each naming its extension,
its font and its reason, so a theme that got three face paths wrong learns all
three from one build rather than one per run. The check reads the files rather
than the cached definition set, because a refusal removes the definition from
that set and a cache-served set cannot report what is missing from it. That is
also the right moment: adding or editing a font is a build-time act, since a new
font's `.font-{selector}` utility only exists once prepare has written it into
the generated stylesheet.

**Which outcome a problem gets is a property of the check, not of the stage.** A
problem the font cannot survive is a **font declaration refusal**: dropped at
discovery, fatal at prepare. A problem the font does survive is a **font
declaration report**: logged at warning, and prepare succeeds. Every check that
existed before this release is a refusal — no `family`, no `type`, an
unsupported `type`, a derived id equal to a font role name, and for a local
font an unresolvable provider, no `faces`, a face with no `src`, and a `src`
that is not on disk. The selector–role collision is the only report, because it
is the only problem where the font is built correctly and only the author's
expectation is wrong.

**Nothing newly refuses, and this is not a deprecation cycle.** Every one of
those conditions already failed before this release — harder, earlier and with
less information. At page render this is strictly a relaxation: a site that
white-screened now renders. At prepare the outcome is unchanged, because the
plugin exception propagated out of the build event and failed the build already;
only the message is different, and it now names the extension. Nothing is
removed, nothing is tightened, and there is no version in which a site should
change a declaration it did not have to change before.

**A sound site emits exactly the same bytes.** Where every declared font is
processable — which is every site whose build passes today — the generated
stylesheet, the inline CSS and the Google font link are byte-identical before
and after, so this lands without a rebuild that moves anything. The only
observable difference needs a declaration that was already broken.

**One thing to know about the log.** Discovery is cached, so a dropped font is
logged once per discovery cache rebuild, not once per request. That is what
makes logging there affordable, and it is also why the log alone is not enough:
a site whose cache has been warm for a week has one old line and a missing font.
Prepare is the half that tells the author now.
