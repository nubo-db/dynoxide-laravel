# Releasing

This package has its own version, separate from Dynoxide's. It needs
`nubo-db/dynoxide`, the PHP package that runs Dynoxide, and requires it with a
caret constraint (`^1.8` at the time of writing).

## When a Dynoxide or core package release needs one here

Usually it doesn't. When `nubo-db/dynoxide` publishes a new version inside the
constraint, such as 1.8.1 or 1.8.1.1, anyone running `composer update` gets it
without a release here. Check it works with this package:

1. Once the new core version is on Packagist, run CI on `main`:

   ```sh
   gh workflow run ci.yml --ref main
   ```

   The `prefer-stable` legs install the newest core, and the `prefer-lowest`
   legs keep testing the oldest one the constraint allows.
2. If it's green, there's nothing to release. If it fails, fix it here and
   release the fix as below.

A release here is needed when this package's own code changes, or when it
needs a newer core than its constraint allows. In that case raise the
constraint in `composer.json` and say so in the CHANGELOG entry.

## Cutting a release

Packagist publishes a tag as soon as it's pushed, and the repository's tag
rulesets let only admins create a tag and stop anyone moving or deleting one.
So check everything before the tag goes up, and fix a mistake with a new
version, not a retag.

1. In `CHANGELOG.md`, put the Unreleased entries under
   `## [A.B.C] - YYYY-MM-DD`, following SemVer. If the core constraint changed,
   open the entry with the line "Needs `nubo-db/dynoxide` X.Y.Z or later,
   which runs Dynoxide X.Y.Z."
2. Merge the change through a pull request once CI is green.
3. Tag the merge commit on `main` and push the tag:

   ```sh
   git tag -s vA.B.C -m vA.B.C <full SHA>
   git push origin vA.B.C
   ```

4. Check that Packagist lists the version:
   `composer show --all nubo-db/dynoxide-laravel`.
