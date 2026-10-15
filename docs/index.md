# omnilab/rhea

## Installation

```sh
composer require omnilab/rhea
```

```php
use Omnilab\Rhea\RheaSourceFactory;

$rhea = (new RheaSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, asked by Rhea
    'enzymes' => 25,                   // optional: reviewed UniProt proteins entity() asks for; 0: none (one call less)
]);
```

## The scheme

A Rhea reaction is named in the core's `rhea` scheme by its number (`18157`, written
`RHEA:18157`), read from `RHEA:18157`, `rhea:18157` and its page `rhea-db.org/rhea/18157`. Rhea
gives each reaction four ids: the master (direction undefined, `=`), left to right (`=>`), right to
left, and both ways (`<=>`). Any of them gives the master reaction, which carries the three others
as identifiers: the `Merger` knows the reaction by any of the four (KEGG links to the
bidirectional one, `18160`). A KEGG reaction (`kegg.reaction:R00200`) gives the Rhea reaction that
names it.

## Which APIs

| Method | Calls |
|---|---|
| `entity()` | Rhea's SPARQL endpoint `sparql.rhea-db.org/sparql`, once: the master reaction (the id itself, or the master of which it is a direction; or the reaction whose quartet links the KEGG reaction), its fields, its quartet's cross-references, its sides' participants with their ChEBI accession, name and coefficient. Then UniProt's REST API `rest.uniprot.org/uniprotkb/search`, once (unless `enzymes` is 0): the reviewed proteins whose catalytic activity names any of the four ids, the first `enzymes` of them, and their number (`X-Total-Results`) |
| `search()` | Rhea's REST search `rhea-db.org/rhea?query=...&format=json&limit=...&offset=...` - one more than the page asked, to know whether another follows (Rhea's `count` is the page's, not the total: no total) |

The SPARQL query has no `DISTINCT` nor `LIMIT` in its sub-query: either took the endpoint over a
minute on 2026-10-15, against about 3 s without. Rhea's REST "table" output (TSV) was not chosen
for `entity()`: its text query matches other reactions too (`18157` also finds RHEA:32167), and it
does not give a reaction's directions.

## What an entity holds

`id` (`rhea:18157`, the master), `label` (its equation), `domain` chemistry, `kind` `reaction`,
`url` (its page: it answers a browser check to a program), and:

| Key | Value |
|---|---|
| `status` | `approved`, `preliminary`, `obsolete` |
| `balanced`, `transport` | booleans |
| `left`, `right` | one per participant, in curated order: `pyruvate (CHEBI:15361)`, `2 H(+) (CHEBI:15378)`; a polymer or a generic compound keeps Rhea's accession (`POLYMER:...`, `GENERIC:...`) |
| `comment` | Rhea's comment, when there is one |
| `references`, `pubmed` | how many citations; one PubMed id each |
| `xref` | MetaCyc, EcoCyc, M-CSA ids (`METACYC:PEPDEPHOS-RXN`): bases the core has no scheme for |
| `enzymes` | how many reviewed UniProt proteins catalyse it (UniProt's count; 111 for 18157) |

`identifiers`: the three other ids of its quartet, its KEGG reaction. `related`: `participant`
(ChEBI ids of both sides), `enzyme` (EC numbers), `go` (its GO molecular function), `event`
(Reactome reactions: a species' instance, not the same thing), `protein` (the first `enzymes`
reviewed UniProt accessions). The `Collector` follows none of the related things.

A search page's entity holds the equation, status, balance, transport, comment and the ChEBI
participants of its HTML equation: `entity()` reads it whole.

## The Collector: from KEGG into Rhea

KEGG's reaction R00200 carries `rhea:18160`; Rhea's 18157 carries `kegg.reaction:R00200`. With
both configured, `$collector->collect('kegg', 'R00200')` follows R00200 into Rhea and merges
(omnilab/kegg is not tested live: KEGG's API is for academic users). From Rhea,
`$collector->collect('rhea', '18157', 1, ['kegg'])` follows the KEGG reaction.

## Verified, and not

Against the real services on 2026-10-15: RHEA:18160 (the master 18157, its quartet, its five
participants, EC 2.7.1.40, GO:0004743, R00200, R-HSA-71670, eleven citations, 111 reviewed
proteins), the same reaction by R00200, an unknown id (no rows: null); the search for "pyruvate
kinase" in two pages of three (six reactions), for a text nothing matches. The fixtures in
`Tests/Fixtures/` are those answers. Calls took 1 to 3 s (SPARQL), 0.3 to 2.3 s (UniProt), 0.3 to
1 s (search).

Not verified: a rate limit (none published, none met); reactions with polymers, generic
compounds or coefficients `n` (read as given); an obsolete reaction's replacement (not read).
