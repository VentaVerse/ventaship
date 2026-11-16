# Contributing

## Versioning

Ventaship uses semantic versioning, MAJOR.MINOR.PATCH, starting at 1.0.0. The current version is in the `VERSION` file at the repo root.

- A release with a new feature raises MINOR: 1.0.0 becomes 1.1.0.
- A release without a new feature (fixes and updates only) raises PATCH: 1.0.0 becomes 1.0.1.
- A release that breaks how connected stores use the API (a removed or renamed field or endpoint) raises MAJOR: 1.4.2 becomes 2.0.0.
- A pre-release takes a suffix, such as 1.1.0-beta.1.

Between releases, `VERSION` holds the next release's number. The first change after a release raises it once: PATCH for a fix or update, MINOR for a new feature, MAJOR for a breaking API change. Later changes before the release leave it alone, unless they need a bigger raise (a feature after a fix moves 1.5.1 to 1.6.0, once).

## Changelog

Every change adds a line in the right section under the top heading of `CHANGELOG.md`, `## X.Y.Z (unreleased)`, where X.Y.Z matches `VERSION`. The first change after a release adds that heading and raises `VERSION` in the same commit.

There are three sections. Leave out any that are empty.

- **New features**: something a person can do that they could not do before, such as a page, an action, a setting, a report or an API endpoint.
- **Updates**: something that existed now works differently and a person would be surprised or need to act, such as a moved page, a changed rule, or a removed or renamed API field.
- **Bug fixes**: something gave a wrong result, lost data or failed, and now works.

Everything else (labels, wording, layout, tests, internal code, a default only a new install sees) is one line, `- Some minor improvements.`, that lists nothing.

Each line is one sentence written for the person using Ventaship. No bold, no emoji, no upgrade or install steps.

## Releases and tags

1. Change `## X.Y.Z (unreleased)` to `## X.Y.Z (YYYY-MM-DD)` in one commit.
2. Tell the maintainer that commit, to tag `vX.Y.Z`.
3. The release notes are that version's changelog section.

Tags are the maintainer's. Never create or push tags.
