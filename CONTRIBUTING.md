# Contributing

## Versioning

Ventaship uses semantic versioning, MAJOR.MINOR.PATCH, starting at 1.0.0. The current version is in the `VERSION` file at the repo root.

- A release with a new feature raises MINOR: 1.0.0 becomes 1.1.0.
- A release with fixes only raises PATCH: 1.0.0 becomes 1.0.1.
- A release that breaks how connected stores use the API (a removed or renamed field or endpoint) raises MAJOR: 1.4.2 becomes 2.0.0.
- A pre-release takes a suffix, such as 1.1.0-beta.1.

The version changes only when a maintainer releases. It never changes per commit or per push. Between releases, `VERSION` stays at the last release.

## Changelog

Every change adds a line under `## Unreleased` at the top of `CHANGELOG.md`, in the right section. Unreleased work is the internal note: it stays under `## Unreleased` until a maintainer releases.

There are three sections. Leave out any that are empty.

- **New features**: something a person can do that they could not do before, such as a page, an action, a setting, a report or an API endpoint.
- **Updates**: something that existed now works differently and a person would be surprised or need to act, such as a moved page, a changed rule, or a removed or renamed API field.
- **Bug fixes**: something gave a wrong result, lost data or failed, and now works.

Everything else (labels, wording, layout, tests, internal code, a default only a new install sees) is one line, `- Some minor improvements.`, that lists nothing.

Each line is one sentence written for the person using Ventaship. No bold, no emoji, no upgrade or install steps.

## Releases and tags

1. List what is under `## Unreleased` so the maintainer picks the version number.
2. Turn `## Unreleased` into `## X.Y.Z (YYYY-MM-DD)`, add a new empty `## Unreleased` above it, and raise `VERSION` to the same number, all in one commit.
3. Tell the maintainer the exact commit to tag `vX.Y.Z`.

Tags are the maintainer's. Never create or push tags.
