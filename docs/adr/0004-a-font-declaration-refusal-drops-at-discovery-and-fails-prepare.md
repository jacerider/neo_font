# 0004 — A `neo_font` declaration refusal drops at discovery and fails prepare, never throws at discovery

**Status:** accepted
**Date:** 2026-08-23
**Context:** `neo_font` — definition processing, the font declaration check, and every future check
added to a font declaration
**Plan:** `docs/plans/neo-font-declaration-refusal/`

## Decision

A **font declaration refusal** — a problem that makes it impossible to build a font from a
declaration — has exactly two effects, and no third:

1. **At discovery**, the definition is logged at error and **dropped**. That font stops existing
   for the rest of the request. Every other declared font is unaffected, and the request completes.
2. **At prepare**, the **font declaration check** fails the build, naming the declaring extension,
   the font key and the reason, for every problem it found.

Discovery never throws. Not for a missing family, not for an unsupported type, not for a font file
that is not on disk. Every check `neo_font` has, and every check it gains, resolves to a **font
declaration problem** with a severity; a refusal takes the two effects above and a **font
declaration report** takes neither, being logged at warning while the font is built normally.

This is the answer to the question `neo-font-selector-collision`'s spec left open when it deferred
the promotion of the **selector–role collision**: promoting a report to a refusal means changing
its severity, not restoring a throw.

## Why this is surprising

A plugin manager's `processDefinition()` throwing on a malformed definition is the Drupal idiom.
Core does it, every contributed plugin type does it, and a reader opening this class will find a
validation pass that logs and drops instead — which reads as swallowing errors, the thing code
review exists to catch.

What makes it correct is not local to the class. It is *who asks for the definitions*:
`neo_font` composes the **Google font link** inside `hook_page_attachments`, so the full definition
set is resolved on **every page render**, anonymous ones included. Discovery is cached, but the
throw happens before the cache is written, so the cache is never written and every subsequent
request repeats it. One wrong `src:` in one theme's `*.neo.font.yml` therefore returned a plugin
exception for every request on the site until someone fixed the file — and took every other
extension's fonts down with it, because the throw aborts the whole discovery pass rather than the
one definition that caused it.

No reader can reach that conclusion from `FontPluginManager` alone. That is what this ADR is for.

## What it costs

**A broken font becomes quiet at runtime.** A site that used to white-screen now renders with a
font missing and a line in the log. That is better, but it is not louder, and a site whose
discovery cache has been warm for a week has one old log line and a page that looks subtly wrong.
The mitigation is the second half of the decision, not an accident of it: prepare refuses, and
prepare is a step a font change cannot skip — a new font's `.font-{selector}` utility only exists
once prepare has put it in the generated stylesheet and Vite has compiled it. The author who broke
the declaration is at a console running a build.

**A site that never prepares never sees the refusal.** A production environment deploying a
prebuilt `dist/` gets the drop and the log and nothing else. That is the correct trade in that
direction — a deploy is the worst possible moment to discover a YAML mistake, and the build that
produced the artefact already refused it.

## Alternatives considered

**Keep throwing at discovery, and add a prepare-time check beside it.** Rejected: the throw is the
defect. Adding an earlier, friendlier failure does not stop the later, fatal one from firing on the
next cache-cold request, and a site with a broken declaration would then fail twice — once
usefully, once catastrophically.

**Throw at discovery only for the "serious" checks, and drop the rest.** Rejected: there is no
principle that ranks them. A font with no family and a font whose file is missing both end the same
way — the font is not in the stylesheet — and any line drawn between them is drawn on how bad the
YAML *looks*, not on what the site loses. Splitting by severity is right; splitting by *stage* is
not, which is why the severity axis exists and both severities behave the same way at both stages.

**Report the refusal on the status report (`hook_requirements`) instead of at prepare.** Rejected
on the same grounds the sibling plan rejected it for the collision: it addresses an audience that
is not the one who made the mistake, and the module's Drupal 10 support forces a choice between a
deprecated hook signature and an attribute hook that line does not honour. Prepare is where the
author already is.

**Push the refusal into `neo_build`, as a prepare notice with a severity.** Rejected: the prepare
result carries no severity by design (`CONTEXT.md`, **retirement over refusal**), and every
`neo_build` plan's Out of Scope forbids widening the build-event surface. `neo_font` refusing from
its own subscriber achieves the same failure without asking nine completed plans to reopen.

## Consequences

Every check added to a `neo_font` declaration from now on answers one question — can a font be
built from this declaration? — and the answer picks its severity. The stage behaviour follows
automatically, and no future check has to relitigate where it belongs.

The **selector–role collision**'s promotion becomes a one-line severity change rather than a
decision, which is what makes deferring it across a release cheap.

If the report side ever grows past one member, surfacing reports at prepare — which today are
log-only — becomes worth reopening, and that would be the point at which the `neo_build` prepare
result's missing severity axis has to be faced rather than worked around.
