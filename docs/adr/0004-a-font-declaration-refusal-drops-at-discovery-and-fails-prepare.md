# 0004 — A font declaration refusal drops at discovery and fails prepare, never throws

**Status:** accepted · **Date:** 2026-08-23
**Context:** `neo_font` — definition processing, the font declaration check, every future check
**Issue:** jacerider/neo_font#5

**Decision.** A **font declaration refusal** — a problem that makes a font impossible to build — has
two effects: at discovery the definition is logged at error and dropped, so it stops existing for
the request and every other font survives; at prepare the **font declaration check** fails the
build, naming extension, key and reason per problem. Discovery never throws — not for a missing
family, an unsupported type or a file not on disk. Every check yields a **font declaration problem**
with a severity: a refusal takes both effects, a **font declaration report** takes neither and logs
at warning, so promoting the **selector–role collision** is a severity change, not a new throw.

**Why it needs recording.** A plugin manager's `processDefinition()` throwing on a malformed
definition is the Drupal idiom, so a pass that logs and drops reads as swallowing errors. What makes
it correct is who asks: `neo_font` composes the **Google font link** in `hook_page_attachments`, so
every page render, anonymous included, resolves the full set. The throw fires before the discovery
cache is written, so every request repeats it: one wrong `src:` in one theme's `*.neo.font.yml`
returned a plugin exception on every request until it was fixed, and took every other extension's
fonts down, since the throw aborts the whole pass. None of that shows in `FontPluginManager`.

**Rejected.** Keep the throw, add a prepare check beside it — the throw is the defect; it still
fires on the next cache-cold request, so a broken declaration fails twice: usefully, then fatally.
Throw only for the "serious" checks, drop the rest — no principle ranks them: a font with no family
and one whose file is missing both end absent from the stylesheet, and any line between them is
drawn on how bad the YAML looks, not what the site loses; split by severity, never by stage.
Report on the status report (`hook_requirements`) — as the sibling collision plan found, it
addresses an audience that did not make the mistake, and Drupal 10 support forces a choice between a
deprecated hook signature and an attribute hook that line does not honour; the author is at prepare.
Push the refusal into `neo_build` as a prepare notice with severity — the prepare result has none by
design (**retirement over refusal**) and `neo_build` plans forbid widening the build-event surface;
its own subscriber fails the build and reopens nothing. Reopen if reports outgrow one member.

**Cost.** A broken font is quiet at runtime: a site that used to white-screen renders with a font
missing and a log line that a week of warm cache makes old while the page looks subtly wrong. The
mitigation is the second half: no font change can skip prepare — a new font's `.font-{selector}`
utility exists only once prepare emits it and Vite compiles it — so the author is at a console
running a build. A site deploying a prebuilt `dist/` gets only the drop and log: the right trade, as
a deploy is the worst moment to find a YAML mistake and the build that made it already refused.
