# CONTEXT — neo_font

Terms specific to Neo's font layer: the YAML font declarations extensions write, the font
plugins discovered from them, and the roles and CSS the build emits. One entry per term: what
it IS, then the names not to use for it.

## Fonts (`neo_font`)

**Font definition** — one entry in a `*.neo.font.yml` file, discovered from any module or theme
and processed into a font plugin: a family, a type, a generic fallback and, for a local font, its
faces. Its machine key becomes the definition's id with underscores replaced by hyphens —
`roboto_slab` is what a theme declares, `roboto-slab` is what settings and CSS name, and the
plugin id stays the underscored form. _Avoid:_ "font entry", and "font" unqualified where the
instantiated plugin is meant.

**Font type** — `local`, `google` or `generic`; the closed set a definition's `type` may take. A
generic font carries a raw CSS fallback stack and is what the other two point at. _Avoid:_ "font
kind", "font source", and above all "font role", which is an unrelated axis.

**Font role** — one of five named intents a site points at a declared font from
`/admin/config/neo/font`: `primary`, `secondary`, `accent`, `heading`, `ui`. Each emits a
`font-{role}` utility resolving through a `--font-{role}-family` variable, so swapping the font
behind a role needs no template edit and no asset rebuild. _Avoid:_ "setting type" — the code's
name for the same list, which describes where roles are stored rather than what they are — and
"font slot".

**Font selector** — the class suffix of the `.font-{selector}` utility a font gets for itself,
declared per definition and defaulting to the definition's id. It is the half of a definition that
reaches CSS, and it shares a keyspace with the font roles. _Avoid:_ "font class", "font name".

**Font stack** — the CSS `font-family` value a font resolves to: its quoted family followed by its
generic's fallback stack. _Avoid:_ "font family", which is one name inside the stack.

**Font face** — one `@font-face` rule's worth of a local font: a source, and the weight, style,
display and unicode range it applies to. Those five are the **merge key**: faces agreeing on all of
them are merged into a single rule with a multi-source `src`. The three override properties —
ascent, descent and line-gap — sit outside the key, so two faces differing only in an override
merge and the first one's overrides win. `display` also honours a legacy `swap` key as an alias and
falls back to `swap` when neither is declared. _Avoid:_ "font file" — one face may list several.

**Font plugin manager** — `plugin.manager.neo_font`: the service that discovers `*.neo.font.yml`
declarations, runs **definition processing** over each one, performs **generic resolution**,
instantiates a font on demand, and composes the **Google font link**. It also owns the two closed
lists the module is built from, the **font types** and the **font roles**. It knows nothing about
settings — which font a role points at is the **role resolver**'s question — and it has no
filesystem role: it reads the disk only to check that a declared **font face**'s source exists,
injects neither a file system nor a file URL generator, and owns no directory, because a local font
is served from the declaring extension's path. _Avoid:_ "font manager" where the **role resolver**
is meant, and "font discovery", which is only the YAML scan it wraps.

**Font settings plugin** — the `neo_settings` plugin behind `/admin/config/neo/font`: it renders
the font preview tables, one per **font type**, and the five **font role** selects whose values are
the stored **font settings**. It is what the settings repository instantiates in its own
constructor, and it injects the **font plugin manager** and nothing else — never the **role
resolver**, which would close a container cycle back through that repository, and which answers the
wrong question anyway: the form exists to choose a role's font, so it needs every declared font
rather than the one already chosen. From the `neo-font-settings-injection` spec onward it overrides
no constructor and takes the manager onto a protected property in `create()`, because the
serialization trait every settings plugin carries cannot restore a private one. _Avoid:_ "the font
settings form" (the form is one method on it), and "font settings", which is the stored config.

**Role map** — role → font, for the roles the active settings resolve: the pairing that is the
module's reason to exist. A role whose configured font id matches no definition is absent from the
map rather than present and empty. _Avoid:_ "font settings" (that is the stored config), "role
mapping".

**Role resolver** — `neo_font.role_resolver`, the single owner of the role map and of the
instantiated font list behind it. Both build subscribers consume it and inject nothing else, and
it reads settings when asked rather than when constructed. It is a service rather than a method on
the font plugin manager because the settings repository builds the font settings plugin, which
injects that manager — a manager holding the repository would close a container cycle. Settled by
the `neo-font-role-resolver` spec, which found the rule written out twice, once per subscriber.
_Avoid:_ "font resolver" (it is named for the role map), and "font manager", which is
`plugin.manager.neo_font` — discovery and instantiation, knowing nothing about settings.

**Selector–role collision** — a font definition whose font selector equals a font role name, so
the font's own `.font-{selector}` utility and the role's `font-{role}` token claim the same key in
the Tailwind theme fragment and one silently destroys the other. It can only arise from an
explicitly declared selector: a selector left to default takes the definition's id, and a
definition whose id equals a role name is already dropped at discovery. Reported at discovery from
the `neo-font-selector-collision` spec onward; refused in a later release. It is the only **font
declaration report** there is — the one problem a font is built correctly in spite of — so
promoting it means changing its severity to **font declaration refusal**, not adding a throw.
_Avoid:_ "selector clash", and "font conflict", which names neither of the two things that
conflict.

**Warn before refuse** — the release sequence a `neo_font` declaration check follows when it
tightens what a font definition may contain: the condition is detected and reported for one
release, so a site that trips it learns before anything of theirs breaks, and only then does the
check become a refusal. It is `neo_build`'s **declaration refusal** discipline stretched across two
releases, and it is a release-level sequence rather than a ticket-level one — the two halves have
to ship separately, because a single working tree carrying both never gives any site a version
that only warns. _Avoid:_ "deprecation" (nothing is being removed) and "soft failure".

**Definition processing** — the pass the font plugin manager runs over each discovered font
definition before it can become a font plugin: it checks a definition that declares no family, no
font type or an unsupported one; derives the definition id (underscores to hyphens), the label
(falling back to the family) and the font selector (falling back to the id); checks an id equal to
a font role name and reports a **selector–role collision**; and, for a local font, resolves the
declaring extension and rewrites each font face's source to a site-root path. From the
`neo-font-declaration-refusal` spec onward it **throws nothing**: each check yields a **font
declaration problem**, and the pass logs them and drops the definition. _Avoid:_ "validation" — it
derives as much as it checks — and "discovery", which is the YAML scan that feeds it.

**Font declaration problem** — one thing wrong with a font declaration, carrying the declaring
extension, the font key as the YAML spells it, what is wrong, and a severity: **font declaration
refusal** or **font declaration report**. Every check in **definition processing** produces one
instead of throwing, so the same finding can be acted on differently at discovery and at prepare.
_Avoid:_ "error", "violation" — one of the two severities is not an error at all.

**Font declaration refusal** — the severity of a problem the font cannot survive: it is impossible
to build a font from the declaration. Discovery logs it at error and produces a **dropped font
definition**; prepare **fails**. The `neo_font` counterpart of `neo_build`'s **declaration
refusal**, and settled by the same rule — refuse what would produce a bad build. All eight of the
module's checks are refusals: no family, no font type, an unsupported font type, an id equal to a
font role name, an unresolvable provider, no font faces, a face with no source, and a source absent
from disk. See ADR 0004 for why it is never a throw at discovery. _Avoid:_ "fatal", "hard failure".

**Font declaration report** — the severity of a problem the font survives: it is built correctly,
and only the author's expectation is wrong. Discovery logs it at warning and keeps the definition;
prepare succeeds and does not surface it. The counterpart of `neo_build`'s **retirement over
refusal** warn case, and the **selector–role collision** is the only member. _Avoid:_ "soft
refusal", "deprecation".

**Dropped font definition** — a definition a **font declaration refusal** removed from the set
discovery returns, before **generic resolution** runs. That font does not exist for the rest of the
request: no `.font-{selector}` utility, no `@font-face` rule, no entry in the **Google font link**,
no option on the settings form and no **role map** entry. Every other declared font is unaffected,
which is the point — until the `neo-font-declaration-refusal` spec, one bad declaration threw out
of the whole pass and took every font with it, on every page render. _Avoid:_ "skipped", "disabled
font".

**Font declaration check** — the prepare-time pass: a build-event subscriber re-runs the checks
over the **declaration files themselves** and fails the build when any **font declaration problem**
is a refusal, listing every one of them with its extension, its font and its reason. It reads the
files rather than the cached definitions on purpose — a refusal removes the definition from that
set, so a cache-served set cannot report what is missing from it. _Avoid:_ "font validation", and
"the font check" where **definition processing** is meant; they share one internal pass but are two
callers of it.

**Generic resolution** — the step that runs once every definition has been processed, replacing a
non-generic definition's `generic` key — which names another font definition — with that
definition's raw fallback stack. Because it runs across the whole discovered set, a font may name a
generic declared by a different extension. The same step sorts the definitions into natural label
order, which is the order every consumer sees. _Avoid:_ "fallback lookup", "generic inheritance".

**Google font link** — the three head links `neo_font` attaches on every page render when at least
one Google font is declared: the `css2` stylesheet link, composed from every Google definition's
family and its optional `spec`, and the `fonts.googleapis.com` and `fonts.gstatic.com` preconnect
hints. Absent entirely when no Google font is declared. The three links carry no cache metadata:
nothing about them varies per request, and the definition set behind them changes only when a
declaration file does. _Avoid:_ "the Google stylesheet" — there are three links, and two of them are
constants.

**Font fixture module** — `neo_font_test`, the hidden test-only module (with a companion test
theme) whose `*.neo.font.yml` declares a controlled set of font definitions: a generic, a Google
font with a spec, and a local font with a real file on disk. It exists because discovery, generic
resolution and local-font processing can only be exercised against extensions whose declarations
the test owns; a later `neo_font` plan that changes what a declaration may contain extends it
rather than starting one. Every font it declares is **sound** — that is the one thing it promises,
and it is why the `neo-font-declaration-refusal` spec adds a *second*, separate fixture for
declarations that fail on purpose rather than extending this one. _Avoid:_ "test fonts", "font
stub".

**Broken-declaration fixture** — the companion hidden test module whose `*.neo.font.yml` declares
fonts that fail on purpose, one per **font declaration refusal**. It exists because the **font
declaration check** reads the declaration files over the real extension list, so the only way to
prove it finds a problem is to have an extension that has one — and because after the
`neo-font-declaration-refusal` spec a broken declaration no longer breaks the discovery pass, which
is itself what the fixture asserts. Never enabled beside the clean **font fixture module** in a
test that assumes every declared font survives. _Avoid:_ "bad fonts fixture", and "the font fixture"
unqualified, which is the clean one.

