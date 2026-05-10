---
name: afk
description: Autonomously triage all open GitHub issues, resolve as many as possible via sequential subagents committing directly to main, and cut a versioned release with a runtime ZIP asset attached for the GitHub-Releases auto-updater. ONLY use when the user explicitly invokes `/afk` or says they are going AFK and want this batch run. NEVER auto-trigger — this skill commits directly to `main`, pushes, and creates a public GitHub release. Excluded scope: any issue labeled `parked` is left untouched. Project-local to kntnt-gpx-blocks.
---

# AFK Run

Execute this procedure autonomously when the user invokes `/afk` or otherwise tells you they are going AFK and want a batch run. Use extended thinking generously for both yourself and every subagent — there is no time pressure, prioritise correctness over speed. Send push notifications only at the trigger points listed at the end.

## Step 0: Pre-flight

1. Run `git status`. If the working tree is not clean (any modified, deleted, staged, or untracked files): STOP and ask the user to commit or clean first. No part of this run may begin on a dirty tree.
2. Run `git pull --ff-only origin main`. If non-fast-forward: STOP and report.
3. Verify `gh auth status` is OK. If not: STOP.
4. Verify the release tooling is reachable: confirm `./build-release-zip.sh` exists and is executable. If missing or non-executable: STOP.

If any pre-flight step fails, send the STOP push notification and halt the run.

## Step 1: Triage all open issues

Fetch all open issues with their labels and bodies in JSON form so you can filter and reason over them:

```
gh issue list --state open --limit 200 --json number,title,labels,body
```

**Exclude `parked` issues entirely.** Filter out every issue whose `labels[].name` contains `parked` *before* doing anything else. Parked issues must not be triaged, commented on, relabelled, or touched in any way during this run. If an issue carries both `parked` and `needs-triage`: leave it untouched. Record the count in your final report as `Parked, untouched: <N>`.

For every remaining open issue, in this order:

**A. Already-implemented check.** Read the issue body and inspect `git log` plus the relevant files. Only close an issue if you can cite (i) a specific commit SHA where the fix landed, AND (ii) the file path and approximate line where the code now satisfies the issue's done-state. At the slightest doubt, keep the issue open and continue triage. The reason for this strict bar is that closing a real issue as already-fixed silently drops work; opening an issue that is genuinely done can be closed later with no harm. If you do close it: comment `Already implemented in <sha> — closing.` and close. Skip to the next issue.

**B. Set `priority:*`.** Exactly one of `priority:high` / `priority:medium` / `priority:low`. Judgement based on issue text and user impact:
- high = blocks core functionality or has security implications
- medium = clear value but not blocking
- low = nice-to-have / cosmetic

If a priority label already exists: keep it.

**C. Set `type:*`.** Exactly one of `type:bug` / `type:feature` / `type:chore`:
- bug = defect in existing functionality
- feature = new functionality
- chore = refactor, documentation, tooling, maintenance

If a type label already exists: overwrite it (your fresh judgement supersedes any prior triage).

**D. Migrate away from legacy labels.** If the issue carries `bug`, `enhancement`, or `documentation` (the older generic labels): remove them now that `type:*` is set.

**E. Write acceptance criteria** as a triage comment:

```
## Triage acceptance criteria
- [ ] <concrete criterion 1>
- [ ] <concrete criterion 2>
- [ ] Tests added/updated and pass: `composer test`, `npm run test:js`, `wp-scripts test-unit-js`
- [ ] Build passes: `npm run build`
- [ ] PHPStan clean: `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`
- [ ] Lint clean (when src/blocks/ touched): `npx wp-scripts lint-js src/blocks/`
- [ ] Docs updated where the change affects public behaviour
```

Tailor the list to the issue. If the body already lists acceptance criteria: incorporate them into the list rather than duplicating.

**F. Remove `needs-triage`** last, once A–E are complete.

## Step 2: Build a dependency DAG and decide order

For every issue STILL open after Step 1:

**A. Identify dependencies.** For every pair (X, Y), determine whether Y requires that X land first. Criteria:
- Y modifies architecture that X builds on.
- Y assumes a bug X is fixed.
- X and Y touch the same files in ways where one change would obscure the other.

Read the code, not just the issue text. Ambiguous cases: treat as independent but flag in your report.

**B. Produce the DAG** as an explicit data structure in your working memory:

```
{ <issue_number>: { deps: [N, M, ...], size: small|medium|large, priority: high|medium|low, type: bug|feature|chore } }
```

**C. Topological sort with tie-break.** Among issues whose dependencies are satisfied:
1. Sort by `size` ascending (small → large) — quick wins first.
2. Tie-break by `priority` descending (high → low).
3. Tie-break by issue number ascending.

**D. Size estimate.** "Size" is your estimate of implementation effort, not the issue's importance:
- small = a CSS tweak, a small render change, documentation
- medium = a handful of files, possibly a new test file
- large = architecture-bearing change, many files, or requiring extensive tests

## Step 3: Resolve issues sequentially via subagents

For each issue in the order from Step 2:

**A. Pre-spawn check.** Are any of this issue's dependencies (direct or transitive) in the `failed` set? If yes: SKIP. Comment on the issue: `Skipped in this AFK round because #<N> failed and this issue depends transitively on #<N> via <chain>.` Mark the issue as `blocked` in your internal status. Move on to the next.

**B. Spawn subagent.** Use the Agent tool with `subagent_type: "general-purpose"` and `model: "opus"`. The prompt to the subagent must include:

- The issue number, the full issue body, the acceptance criteria from Step 1E, the list of dependencies (referencing the commits where each was resolved), and the relevant paths in the codebase.
- The line: *"No time pressure. Use extended thinking generously. The change must be production-quality, not a hack."*

And these hard requirements (pass them verbatim to the subagent):

1. **Change only what this issue requires.** No drive-by refactors, no "while I'm here" fixes, no surrounding cleanup. Three similar lines is fine; do not invent abstractions.

2. **Pre-1.0 policy is in force.** No `deprecated` blocks in `block.json`, no attribute aliases, no "in case the old shape is still around" fallbacks, no migration shims. Pick the clean end-state and break freely. If the issue text seems to ask for backwards compatibility: ignore that part and proceed with a clean break. Why: the plugin has zero installations in the wild while the major version is `0`, and this rule is documented in `AGENTS.md`.

3. **Update relevant files in `docs/`** if the change alters public behaviour. Use the table in `AGENTS.md` to find the right doc(s).

4. **Tests are mandatory, not optional.** For every issue:
   - Choose the right test surface:
     - PHP unit → Pest under `tests/Unit/`
     - PHP integration (requires WP runtime) → Playground test under `tests/Integration/` via `@wp-playground/cli`
     - JS/TS unit inside blocks → `wp-scripts test-unit-js` (Jest, co-located or under the block's `__tests__/`)
     - JS/TS unit outside blocks → the `npm run test:js` suite
   - For `type:bug`: write a regression test that FAILS against the unchanged code, then apply the fix and verify the test now passes. Capture both states (red → green) in your final report as evidence. A test that "always passed" is not a regression test.
   - For `type:feature`: write tests covering each acceptance criterion. Include at least one explicit edge-case test (empty GPX, corrupt GPX, missing `mapId`, different tile providers, consent on/off — whichever is relevant).
   - For `type:chore`: if the refactor touches behaviour, add characterisation tests that lock current behaviour before refactoring. Pure documentation changes need no tests.
   - If a test is genuinely impossible or unreasonably expensive (e.g. tile-provider rendering requiring a headless browser): justify explicitly in your final report and propose a manual test checklist as an issue comment. "It is tedious" is not an accepted reason.
   - Place tests per `docs/file-structure.md` and follow conventions in `docs/testing-strategy.md`. Read both before writing the first test.

5. **Run all five gates before commit.** Every one must pass:
   ```
   npm run build
   composer test
   vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
   npm run test:js
   ```
   Plus, if `src/blocks/` was touched in this issue's diff:
   ```
   npx wp-scripts lint-js src/blocks/
   npx wp-scripts format src/blocks/
   ```
   For `type:bug` issues: additionally confirm that the new regression test fails on `HEAD~1` (stash the fix, run the test, see it fail, restore). A new test that passes against unchanged code does not cover the right thing — rewrite it.

6. **Retry policy.** A "retry" is one full sequence of implementation → gate run. If a gate fails, the retry is consumed regardless of cause. Reading code and planning do not count. You have ONE (1) retry. On retry, do root-cause analysis; do not paper over the failure with a hack.

7. **If retry also fails:** return a structured failure object. Do NOT commit. Leave the working tree clean via `git restore -- .` and `git clean -fd`:
   ```
   { status: "failed", reason: <short>, attempts: [<both attempts>], suggestion: <what is needed to unblock> }
   ```

8. **If everything is green:** commit directly to `main` with the format:
   ```
   <imperative subject line, ~50 chars, no trailing period>

   Closes #<NN>
   ```
   Subject line first, blank line, then `Closes #NN` as the body. No version bump. No `Co-Authored-By` footer (matches existing repo style). Push to `origin main` immediately after commit.

9. **Out-of-scope improvements:** if you discover one, open a NEW issue with the `needs-triage` label. Do NOT bundle it into the current commit.

10. **Return a structured result:**
    ```
    { status: "closed" | "failed", issue: <NN>, sha: <sha or null>, summary: <what was done>, tests_added: [<paths>], red_green_evidence: <for type:bug, the failing-then-passing test names>, new_issues_opened: [<NN>, ...] }
    ```

**C. Handle the subagent's response.**

- If `status: "closed"`: GitHub auto-closes the issue via `Closes #NN` in the commit message. Verify with `gh issue view <NN>` and close manually if it did not. Add to your `closed` set.
- If `status: "failed"`: comment on the issue with the subagent's `attempts` and `suggestion`. Add to your `failed` set, and to the `failed_dag_root` set for cascade-skip in subsequent Step 3A iterations.

**D. Working-tree hygiene between iterations.** After every subagent response (closed OR failed): run `git status`. If the tree is not clean: `git restore -- .` and `git clean -fd` to wipe any subagent leftovers. Log the event in your final report. Never carry residue into the next subagent.

**E. Iterate** until every issue is handled (closed, failed, or blocked).

## Step 4: Version bump, tag, release

**A. Skip-check.** If the `closed` set is empty: skip bump and release entirely. Go straight to Step 5.

**B. Determine bump level** (semver, pre-1.0):
- Any closed issue has `type:feature` → minor (0.X.0, X+1).
- Otherwise (only `type:bug` and/or `type:chore`) → patch (0.x.Y, Y+1).
- "Major" does not apply pre-1.0; even breaking changes go in a minor bump.

**C. Tag-collision guard.** Before doing anything else:
```
git rev-parse "v<X.Y.Z>" 2>/dev/null && STOP and report
```
A pre-existing tag means a prior run got partway through release and needs human inspection.

**D. Update the version string in two files** (must match exactly):
- `kntnt-gpx-blocks.php` — `Version: X.Y.Z` header
- `package.json` — `"version": "X.Y.Z"`

**E. Run all release gates one final time globally:**
```
npm run build
composer test
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
npm run test:js
```
Plus `npx wp-scripts lint-js src/blocks/` if `src/blocks/` was touched in any commit during this run. A green run on each individual issue commit does not guarantee a green run on their union — that is what this gate catches. If anything fails: STOP, report, do NOT release.

**F. Commit and tag:**
```
git add -- kntnt-gpx-blocks.php package.json
git commit -m "Release v<X.Y.Z>"
git tag -a "v<X.Y.Z>" -m "v<X.Y.Z>"
```

**G. Build the release ZIP.** Run `./build-release-zip.sh` from the project root. The script runs a clean `npm ci` + `npm run build`, fetches `composer install --no-dev --optimize-autoloader`, and produces `kntnt-gpx-blocks.zip` in the project root with top-level folder `kntnt-gpx-blocks/` and runtime artefacts only. It then restores the dev composer install. Verify the working tree is still clean afterwards with `git status` — if not, STOP, something in the script changed.

**H. Push commit and tag:**
```
git push origin main
git push origin "v<X.Y.Z>"
```

**I. Create the GitHub release with the ZIP attached.** Build the notes from the closed-issues set, grouped by `type:*` with headings **Features** (`type:feature`), **Fixes** (`type:bug`), **Maintenance** (`type:chore`). Format per line: `- #NN <issue title> (<commit-sha>)`.

```
gh release create "v<X.Y.Z>" ./kntnt-gpx-blocks.zip --title "v<X.Y.Z>" --notes "$(cat <<EOF
## Features
- #NN <title> (<sha>)
...

## Fixes
- #NN <title> (<sha>)
...

## Maintenance
- #NN <title> (<sha>)
...
EOF
)"
```

Omit any heading whose group is empty.

**J. Verify asset content-type.** The auto-updater identifies the right asset by `content_type === "application/zip"`, not by filename. This must be verified:

```
gh release view "v<X.Y.Z>" --json assets --jq '.assets[].contentType'
```

Must return exactly `application/zip`. If anything else (e.g. `application/octet-stream`): re-upload via `gh release upload "v<X.Y.Z>" ./kntnt-gpx-blocks.zip --clobber` and re-verify. If still wrong: STOP and report — the release will not be picked up by the updater otherwise.

**K. Post-release smoke test.** Download the ZIP back from the release URL and verify it contains:
- `kntnt-gpx-blocks/kntnt-gpx-blocks.php`
- `kntnt-gpx-blocks/build/` (with at least one block's compiled output)
- `kntnt-gpx-blocks/vendor/autoload.php`
- No `node_modules/`, no `tests/`, no `.git/`

```
gh release download "v<X.Y.Z>" --pattern "kntnt-gpx-blocks.zip" --dir /tmp/kntnt-release-check
unzip -l /tmp/kntnt-release-check/kntnt-gpx-blocks.zip | grep -E "(kntnt-gpx-blocks\.php|build/|vendor/autoload\.php|node_modules|^.*tests/)"
rm -rf /tmp/kntnt-release-check
```

If any required path is missing or any forbidden path is present: STOP and report. Do NOT delete the release — let the user inspect.

## Step 5: Final report

Send ONE PushNotification with the gist: `AFK run done. Closed: <N>, Failed: <M>, Blocked: <P>, Parked (untouched): <Q>. Release: v<X.Y.Z> | skipped.`

Then write a structured final report in the session:

```
## AFK Run Summary

### Closed (N)
- #NN <title> — <sha> — <one-line description> — tests: <paths>
- ...

### Failed (M)
- #NN <title> — <reason> — <suggestion>
- ...

### Blocked, not attempted (P)
- #NN <title> — blocked by failed #<NN> via <chain>
- ...

### Parked, untouched (Q)
- #NN <title>
- ...

### Newly opened (R)
- #NN <title> — <why opened>
- ...

### Working-tree hygiene events
- After #NN: <what was cleaned, why>
- ...or "none"

### Release
- Bump: 0.A.B → 0.X.Y (<minor|patch>)
- Tag: v<X.Y.Z>
- Asset content-type: application/zip ✓
- Smoke test: passed ✓
- URL: https://github.com/<owner>/<repo>/releases/tag/v<X.Y.Z>
- ...or "Skipped: no issues closed."
```

## Push notification triggers

Be deliberate about pings — the user is asleep:
- Send a notification for any STOP condition (Step 0 failure, Step 4E gate failure, Step 4J/K failure, tag collision in 4C, push failure).
- Send a notification at the end of the run (Step 5) — exactly one, win or lose.
- Do NOT send a notification for individual subagent failures, blocked issues, or transient issues that the run itself routes around. Those go in the final report.

## Cross-cutting policy

- All communication on GitHub (issue comments, commit messages, release notes) in English, matching existing repo convention.
- Follow the coding standard in `docs/coding-standards.md` strictly. No bypassing pre-commit hooks (`--no-verify` is forbidden).
- NEVER `git push --force` or `git reset --hard` against anything that has been pushed.
- NEVER use `git add -A` or `git add .`. Stage files explicitly by name.
- Version bump happens ONCE in Step 4, never per issue.
- No `Co-Authored-By` footer in commits (matches existing repo style).
- Pre-1.0 policy: no backwards-compatibility shims, no deprecations, no attribute migrations. Push back if a subagent or issue body requests them.
