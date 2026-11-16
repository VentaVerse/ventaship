# Contributing

## Versioning

Ventaship uses semantic versioning, MAJOR.MINOR.PATCH, starting at 1.0.0. The current version is in the `VERSION` file at the repo root.

- A change with a new feature raises MINOR: 1.0.0 becomes 1.1.0.
- A change without a new feature (fixes and updates only) raises PATCH: 1.0.0 becomes 1.0.1.
- A change that breaks how connected stores use the API (a removed or renamed field or endpoint) raises MAJOR: 1.4.2 becomes 2.0.0.
- A pre-release takes a suffix, such as 1.1.0-beta.1.

The version tracks the code, not the releases. Each pushed change raises `VERSION` and gets its own heading in `CHANGELOG.md`, whether or not that version is ever released. A release is a version the maintainer chooses to tag and publish; versions in between need not be released.

## Changelog

Every change adds a line under `## Unreleased` at the top of `CHANGELOG.md`, in the right section. When the change is pushed, `## Unreleased` becomes `## X.Y.Z (YYYY-MM-DD)` with `VERSION` raised to the same number in the same commit, and a new empty `## Unreleased` goes above it.

There are three sections. Leave out any that are empty.

- **New features**: something a person can do that they could not do before, such as a page, an action, a setting, a report or an API endpoint.
- **Updates**: something that existed now works differently and a person would be surprised or need to act, such as a moved page, a changed rule, or a removed or renamed API field.
- **Bug fixes**: something gave a wrong result, lost data or failed, and now works.

Everything else (labels, wording, layout, tests, internal code, a default only a new install sees) is one line, `- Some minor improvements.`, that lists nothing.

Each line is one sentence written for the person using Ventaship. No bold, no emoji, no upgrade or install steps.

## Releases and tags

1. The maintainer picks a version that is already in `CHANGELOG.md`.
2. Tell the maintainer the exact commit that raised `VERSION` to it, to tag `vX.Y.Z`.
3. The release notes are that version's changelog section, plus any versions since the last release.

Tags are the maintainer's. Never create or push tags.
