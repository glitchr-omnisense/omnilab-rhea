# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab Rhea: a biochemical reaction by any of its four Rhea ids (18157, 18160) or its KEGG
  reaction (R00200) - its equation, status, balance, transport, sides and their participants,
  citations - its quartet and KEGG reaction as identifiers, its ChEBI participants, EC numbers, GO
  term, Reactome events and the reviewed UniProt proteins that catalyse it as related things; a
  search; through Rhea's SPARQL endpoint and REST search, no key.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/rhea` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
