---
contract_version: 2
kind: tool
service: terminus-site-clone-plugin                  # a Terminus plugin (Composer package pantheon-systems/terminus-site-clone-plugin, type terminus-plugin); nothing runs on Pantheon infrastructure

trigger: tag
merged_implies_deployed: false           # the default branch carries unreleased changes between tags; only a tag publishes
other_paths_to_prod:
  - "pushing ANY tag (any commit, any branch) is a release: Packagist lists it and `terminus self:plugin:install` / `self:plugin:update` can resolve it for users whose Terminus matches `compatible-version`. No tag ruleset exists, so nothing restricts who can push one or from where"
  - "deleting or moving a tag in GitHub changes what Packagist will serve, with no workflow run (never do it)"
  - "editing or deleting a GitHub release does NOT change what `terminus self:plugin:install` resolves (Composer uses the Packagist tag, not the release)"

pipeline:
  system: github-actions            # CI only verifies; the release itself is a tag push, not a pipeline
  workflow: "none releases; verification is in .github/workflows/*.yml"
  jobs_in_order: []
  typical_minutes: 0                # a tag is published the moment it is pushed; Packagist lists it within minutes (inferred)
  minutes_by_change: {}
  watch: "`gh api repos/pantheon-systems/terminus-site-clone-plugin/tags --jq '.[0].name'` then the Packagist check under health; there is no run to watch"
  concurrency_group: null           # none declared
  cancel_in_progress: null
  approval_gate: null               # no GitHub Environment; the human gate is review of the merged PR plus whoever pushes the tag

release:
  mechanism: "push tag <version> on the merged commit on `master` -> Packagist lists it. No workflow runs on a tag; one GitHub release exists (2.1.1, created by hand from the tag with a changelog line); the earlier tags have none"
  tag_scheme: "bare semver, no v prefix (2.1.1)"
  never: "move or delete a published tag (Packagist and Composer lock files key on it); publish a fixed patch instead"
  procedure: "tag the merge commit on master: `git tag <version> && git push origin <version>`. Check first that CI on that commit is green (see automation_blockers) and that `composer.json` `extra.terminus.compatible-version` is what you intend: it decides which Terminus versions can install the release. There is no version field in composer.json to bump (checked)"
  version_source: "the git tag only; composer.json has no `version` key (checked)"

consumers:                          # how a release reaches users; each picks it up on its own schedule
  - {channel: "Packagist pantheon-systems/terminus-site-clone-plugin", pin: "the user's or CI image's resolved version", picked_up_by: "`terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin` (Composer resolves the highest stable tag whose `extra.terminus.compatible-version` fits) and `terminus self:plugin:update`. Packagist lists a new tag on its own, no workflow step (inferred: webhook or crawl; every existing tag is listed)"}
  - {channel: "pantheon-systems/docker-build-tools-ci image (quay.io/pantheon-public/build-tools-ci)", pin: none, picked_up_by: "Dockerfile runs `terminus self:plugin:add terminus-site-clone-plugin` at image build time, so each image build picks up whatever is latest then; images built earlier keep the old version until rebuilt"}
  - {channel: "docs.pantheon.io guides", pin: "install command", picked_up_by: "add-site-clone and the Drupal code guide tell users to install the plugin"}
  - {channel: "docs.pantheon.io Terminus plugin directory", pin: "link only", picked_up_by: "hand-maintained card in pantheon-systems/documentation src/source/content/terminus/08-directory.md; no version is pinned there"}

repo_properties:
  observed_at: 2026-11-10
  trust_level: TL4
  ccb_status: in-ccb
  owner: developer-experience
  repo_type: tool

gating_checks:
  required: ['Wiz IaC Scanner', 'Wiz Vulnerability Scanner', 'Wiz Data Scanner']   # org ruleset 'TL4 - ...'
  approvals: {required: 1, code_owner: true, last_push_approval: false, strict_up_to_date: false, merge_queue: false}   # from `gh api repos/pantheon-systems/terminus-site-clone-plugin/rules/branches/master` (no repository-level ruleset; the org TL4 ruleset applies)
  run_but_not_required: ["PHP Compatibility", "Check for deprecations"]
  conditional: []
  release_gate: "the tag push is the release; nothing stops a tag on a red, unreviewed or non-master commit except process"

environments:
  - {name: packagist, target: "https://packagist.org/packages/pantheon-systems/terminus-site-clone-plugin", gates_prod: true}
  - {name: github-release, target: "github.com/pantheon-systems/terminus-site-clone-plugin/releases/<tag>", gates_prod: false}   # informational; does not change what Terminus resolves

artifact:
  image: null                       # not a container
  file: "the git tag (Composer builds the dist archive from it); no build, no asset"
  tag_scheme: semver
  tag_mutable: false                # by convention; no tag ruleset enforces it
  retention: "tags are kept indefinitely"
  version_signal: "`terminus self:plugin:list --format=json` -> installed_version"
  tag_to_sha: "git rev-list -n1 <tag>   # Packagist's source.reference for the version must equal it (checked for 2.1.1: equal)"

runtime: []                         # the plugin runs inside the user's Terminus, on their machine or CI

deployed_when:                      # ALL must hold
  - "the tag exists on GitHub and `git rev-list -n1 <version>` is the intended commit"
  - "Packagist lists <version> with `extra.terminus.compatible-version` as intended"
  - "`terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin` into a scratch plugins dir installs <version> and `terminus self:plugin:list` shows installed_version == <version>"

health:
  soak_minutes: 0                   # no fleet to watch; health is the smoke checks now and issue reports afterwards
  baseline: "the previous release's behaviour; no request-level signal is available to this repo"
  checks:
    - name: packagist lists the tag
      kind: command
      run: "curl -s https://repo.packagist.org/p2/pantheon-systems/terminus-site-clone-plugin.json | python3 -c 'import json,sys;v=json.load(sys.stdin)[\"packages\"][\"pantheon-systems/terminus-site-clone-plugin\"][0];print(v[\"version\"],v[\"source\"][\"reference\"],v.get(\"extra\",{}).get(\"terminus\",{}).get(\"compatible-version\"))'"
      healthy: "version == <version> and the reference equals `git rev-list -n1 <version>`"   # ran 2026-11-10 for 2.1.1: 2.1.1, reference equal
    - name: installs on current Terminus in a scratch dir
      kind: command
      run: "export TERMINUS_PLUGINS_DIR=$(mktemp -d) TERMINUS_DEPENDENCIES_BASE_DIR=$(mktemp -d) HOME=$(mktemp -d); terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin && terminus self:plugin:list --format=json"   # scratch HOME so ~/.terminus is untouched; run against Terminus 4.3.3 / PHP 8.5.12 on 2026-11-10
      healthy: "exit 0 and installed_version == <version>"
    - name: user reports
      kind: delegate
      run: "new issues on github.com/pantheon-systems/terminus-site-clone-plugin/issues since the release; support reports and #ask-hosting"
      healthy: "no new issue reporting a regression introduced in <version> in the first few days"
  known_baseline_errors:
    - "`terminus self:plugin:install` prints 'Found 3 security vulnerability advisories affecting 2 packages' on every install; it comes from Terminus' own dependency set, not from this plugin"
  dashboards: []
  alerts: []

rollback:
  options:
    - when: "<version> is broken for users and a fix will take more than a few hours"
      kind: re-release-previous
      steps: |
        1. Find the last good tag (`git tag --sort=-v:refname`; the capture below).
        2. Fix forward if possible: revert the bad change on master through a normal PR, then tag the next patch (new version, higher than <version>). Composer resolves the highest compatible stable tag, so users who install or update afterwards get the fixed code.
        3. Do NOT delete or move the bad tag. Deleting it on GitHub does not reliably remove it from Packagist or from Composer caches and lock files. [inferred]
        4. If a bad tag must not be installable at all, a Packagist maintainer can remove that version on the package page. TODO(owner): who has Packagist maintainer access.
        5. Tell users already on the bad version how to pin the last good one (below).
      time_to_rollback_minutes: 30   # inferred: one revert PR (review + merge queue / approval) and one tag push
    - when: otherwise
      kind: roll-forward
      steps: "fix on master, tag the next patch"
      time_to_rollback_minutes: 30   # inferred
  not_restored:
    - "plugins already installed on users' machines and CI images built in the window (docker-build-tools-ci installs plugins at image build) keep the bad version until reinstalled or the image is rebuilt"
    - "Composer lock files that resolved <version>"
  one_way_changes: []               # checked: a release changes no server-side state; a plugin that writes to ~/.terminus or a site config would be the exception, check the diff
  currently_blocked: null
  last_exercised: null
  capture_before_merge: "record the current tag and Packagist state before tagging: `git tag --sort=-v:refname | head -3` and the Packagist check above (latest on 2026-11-10: 2.1.1)"
  who_can: ["@pantheon-systems/developer-experience: write on terminus-site-clone-plugin (merge, tag); Packagist maintainers (TODO(owner): who) can remove a version"]
  verify: "`terminus self:plugin:list --format=json` on a clean scratch install shows the intended (fixed or pinned) version; Packagist's first entry is the fixed tag"
  user_pin: "`terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin:<previous>` installs a pinned version (syntax used by the push-to-pantheon action; a fresh pinned install of rsync 1.1.0 was run 2026-11-10 and installed it). Re-installing over an existing install failed in a scratch dir with 'not a valid Packagist project' (Composer's root-package warning treated as an error): TODO(verify-on-deploy): the working upgrade/downgrade path for an existing install (uninstall, delete the plugin dir, then install pinned?)"

escalation:
  slack: "#ask-hosting"             # escalation channel for everything DevX owns
  owner_team: developer-experience
  paged: false                      # inferred: no alerts exist
  issues: "https://github.com/pantheon-systems/terminus-site-clone-plugin/issues"

preflight:
  - "gh auth status (push access to pantheon-systems/terminus-site-clone-plugin for the tag)"
  - "terminus and php on PATH (for the scratch install check)"

automation_blockers:
  - blocker: "a release is a human tag push with no check that the tagged commit is the reviewed, merged tip of `master`, passed CI or has a matching version"
    clears_when: "the tag is created by a workflow that runs only on `master` after CI passes (action-autotag, as terminus-autopilot-plugin does), or the tag ruleset limits who can push `*` tags to the merged commit"
  - blocker: "no pre-release or staging channel: a tag is immediately what `self:plugin:install` resolves for every user, and `self:plugin:install` cannot be pointed at a candidate without a tag (a pinned `pkg:<version>` or a git URL can)"
    clears_when: "a `-rc` tag convention is adopted and documented (Composer ignores pre-release tags unless asked)"

verified: null                      # no real release has been observed for this contract yet (pass 2)
---

# Deployment

`terminus-site-clone-plugin` is a Terminus plugin. "Deploying" it means publishing a version tag that Packagist serves, so that `terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin` and `terminus self:plugin:update` resolve it. Nothing runs on Pantheon infrastructure; there is nothing to roll out or watch in a cluster. `master` is the only line (`compatible-version: ^2||^3||^4`). The repo commits its `vendor/` directory (9 tracked files); Packagist installs ignore it.

## How a change reaches production

1. **Merge to `master`** through a PR. No repository-level ruleset; the org TL4 ruleset applies. Required checks are the Wiz scanners only; the plugin's own CI (below) does not block a merge, so check it yourself.
2. **Choose the version.** There is no version field to bump: Packagist reads the git tag. Check `composer.json` `extra.terminus.compatible-version`, because it decides which Terminus versions can install the release (see the line above).
3. **Tag the merged commit and push the tag**: `git tag <version> && git push origin <version>`. Tags are bare semver, no v prefix (2.1.1). Nothing runs on the tag. One GitHub release exists (2.1.1, created by hand from the tag with a changelog line); the earlier tags have none.
4. **Packagist lists the tag** by itself (every existing tag is listed, with source reference equal to the tag commit). Users get it the next time they run `terminus self:plugin:install` or `self:plugin:update`; CI images pick it up at their next build.

`master` is ahead of the latest tag (2.1.1, 2025-08-20) only by CI, metadata and dev-dependency commits (checked 2026-11-10: `src/` and `composer.json` runtime requirements match the tag).

Other paths to production: any tag push is a release, whatever the commit. Deleting or moving a tag changes what Packagist serves. Editing a GitHub release changes nothing for Composer.

CI in this repo: `.github/workflows/ci.yml` ('PHP Compatibility' plus a deprecations check); no functional tests run in CI. Recent results: PHP Compatibility: 5 green, 1 red of 6 recent runs; the newest run is 2026-10-05 (later merges were `[skip ci]` management commits).
## How to confirm it deployed

All of these must hold (the checks were run for 2.1.1 on 2026-11-10 and returned the expected data):

```bash
# The tag points at the intended commit
git ls-remote https://github.com/pantheon-systems/terminus-site-clone-plugin.git 'refs/tags/<version>*'

# Packagist lists it, with the same commit and the intended Terminus compatibility
curl -s https://repo.packagist.org/p2/pantheon-systems/terminus-site-clone-plugin.json \
  | python3 -c 'import json,sys;v=json.load(sys.stdin)["packages"]["pantheon-systems/terminus-site-clone-plugin"][0];print(v["version"],v["source"]["reference"],v.get("extra",{}).get("terminus",{}).get("compatible-version"))'

# It installs on a current Terminus, in a scratch location (does not touch ~/.terminus)
export TERMINUS_PLUGINS_DIR=$(mktemp -d) TERMINUS_DEPENDENCIES_BASE_DIR=$(mktemp -d) HOME=$(mktemp -d)
terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin
terminus self:plugin:list --format=json      # installed_version == <version>
```

## How to confirm it's healthy

There is no telemetry, dashboard or alert for this plugin. Health means:

- **At release time:** the install check above on the Terminus versions the plugin claims (`compatible-version`). It was run on Terminus 4.3.3 / PHP 8.5.12. Run the plugin's main command against a test site only if credentials are at hand and the command is read-only.
- **In the following days:** new GitHub issues, support and Slack reports about the new version.

## Rollback

Plugins are normally fixed forward with a new patch tag. Pull a release back only when it is broken for users and a fix will take more than a few hours.

**Before tagging**, record the rollback target: the current latest tag (`git tag --sort=-v:refname | head -3`; latest on 2026-11-10: `2.1.1`).

**To stop a bad release spreading:**

1. Revert the bad change on `master` through a normal PR and tag the next patch version (never reuse the bad version number). Composer picks the highest stable compatible tag, so new installs and updates get the fix.
2. Do not delete or move the bad tag. A Packagist maintainer can remove a version from the package page if it must not be installable at all (TODO(owner): who has that access).
3. Tell affected users how to go back: `terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin:<previous>`. [inferred for an existing install: re-installing over an installed plugin failed in a scratch dir, so uninstall first, `terminus self:plugin:uninstall pantheon-systems/terminus-site-clone-plugin`; TODO(verify-on-deploy)]

**Verify:** a clean scratch install (the commands above) reports the fixed or pinned version.

**What this doesn't restore:** plugins already installed on users' machines, CI images built in the meantime (`docker-build-tools-ci` installs plugins at image build), and Composer lock files that resolved the bad version. A release changes no server-side state.

Rollback has never been exercised.

## Escalation

- Owner: `developer-experience` (code owners `@pantheon-systems/developer-experience`).
- Nobody is paged and there are no alerts.
- Issues: https://github.com/pantheon-systems/terminus-site-clone-plugin/issues
- Slack: `#ask-hosting`, the escalation channel for everything DevX owns.

## Suggested pipeline changes

- Create the tag from a workflow on `master` after CI passes (as `terminus-autopilot-plugin` does with action-autotag), instead of by a hand-pushed tag.
- Add a tag ruleset so only the merged tip of `master` can be tagged and tags cannot be deleted or moved.
- Document the working downgrade path for an installed plugin (see `user_pin`).
