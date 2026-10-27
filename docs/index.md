# omnilab/rhea

Rhea (SIB), the expert-curated knowledgebase of biochemical reactions, through its SPARQL endpoint
(`https://sparql.rhea-db.org/sparql`) and REST search (`https://www.rhea-db.org/rhea`), with
UniProt's REST API for the proteins, for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
reactions by any of their four Rhea ids or their KEGG reaction - equation, status, sides and
participants -, as entities, their ChEBI participants, EC numbers, GO term, Reactome events and
reviewed UniProt proteins as related things; a search. No key.

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

## Credentials

None: Rhea's SPARQL endpoint and REST search, and UniProt's REST API, take no key. Rhea asks
programs to send a contact address in their User-Agent: give `mailto`.

## Options

The factory's options, with the defaults it applies (`RheaSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `enzymes` | `int` | `25` | no | how many reviewed UniProt proteins `entity()` asks for (a second call, to UniProt), kept in `related('protein')`; their number is the `enzymes` property; 0: none, and no call to UniProt |
| `sparql_uri` | `string` | `https://sparql.rhea-db.org/sparql` | no | Rhea's SPARQL endpoint, for `entity()` |
| `uniprot_uri` | `string` | `https://rest.uniprot.org/` | no | UniProt's REST API, for the reviewed proteins (`uniprotkb/search`) |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: Rhea publishes no rate |
| `base_uri` | `string` | `https://www.rhea-db.org/` | no | Rhea's site, for the REST search (`rhea?query=...`) |

## Capabilities

`capabilities()` answers `entity`, `search`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | any of a reaction's four Rhea ids (`RHEA:18160`, `rhea:`, its page) or its KEGG reaction (`kegg.reaction:R00200`): the master reaction answers; another base refused before any call; one SPARQL query, then one UniProt search |
| `search` | yes | Rhea's REST search (a name, a ChEBI id, an EC number, `uniprot:P14618`...), in Rhea's order, 100 a page at most, no total; a domain other than chemistry answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | the three other ids of its quartet and its KEGG reaction; its ChEBI participants, EC numbers, GO term, Reactome events and proteins are `related` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Scheme;
use Omnilab\Rhea\RheaSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$rhea = (new RheaSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'enzymes' => 3]);

$reaction = $rhea->entity('RHEA:18160');      // the bidirectional id: the master reaction answers
echo $reaction->id->display(), ' ', $reaction->label, "\n";
echo '  ', $reaction->value('status'), ', EC ', $reaction->related('enzyme')->value(Scheme::EC), ', KEGG ', $reaction->identifier(Scheme::KEGG_REACTION), ', ', $reaction->value('enzymes'), ' reviewed proteins: ', implode(', ', $reaction->related('protein')->values(Scheme::UNIPROT)), "\n";

foreach ($rhea->search(new Query('pyruvate kinase', limit: 3)) as $found) {
    echo '  ', $found->id->display(), '  ', $found->label, "\n";
}
```

```
RHEA:18157 pyruvate + ATP = phosphoenolpyruvate + ADP + H(+)
  approved, EC 2.7.1.40, KEGG R00200, 111 reviewed proteins: P30613, P14618, P0AD61
  RHEA:11364  pyruvate + ATP + H2O = phosphoenolpyruvate + AMP + phosphate + 2 H(+)
  RHEA:18157  pyruvate + ATP = phosphoenolpyruvate + ADP + H(+)
  RHEA:23052  L-seryl-[pyruvate dehydrogenase E1 alpha subunit] + ATP = O-phospho-L-seryl-[pyruvate dehydrogenase E1 alpha subunit] + ADP + H(+)
```

(as answered on 2026-10-27: three calls)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        rhea: { factory: rhea, options: { mailto: '%env(OMNILAB_MAILTO)%', enzymes: 25 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

The three services' errors are mapped alike (the source's name `rhea` in each message). Before any
call: an identifier of another base (`NotSupportedException`). An id SPARQL answers no row for is
null; a text nothing matches an empty page.

## Verified, and not

Against the real services on 2026-10-15: RHEA:18160 (the master 18157, its quartet, its five
participants, EC 2.7.1.40, GO:0004743, R00200, R-HSA-71670, eleven citations, 111 reviewed
proteins), the same reaction by R00200, an unknown id (no rows: null); the search for "pyruvate
kinase" in two pages of three (six reactions), for a text nothing matches. The fixtures in
`Tests/Fixtures/` are those answers. Calls took 1 to 3 s (SPARQL), 0.3 to 2.3 s (UniProt), 0.3 to
1 s (search).

Again on 2026-10-27: RHEA:18160 giving the master 18157 and three of its 111 reviewed proteins,
the search for "pyruvate kinase" (the example above).

Not verified: a rate limit (none published, none met); reactions with polymers, generic
compounds or coefficients `n` (read as given); an obsolete reaction's replacement (not read).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Rhea, the SIB Swiss Institute of Bioinformatics or UniProt. Rhea is under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/), credited and cited as its pages ask, its
data for research, educational and informational purposes only (not for medical use); UniProt's
answers are under CC BY 4.0 too, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-rhea#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-rhea#responsibility)
section.
