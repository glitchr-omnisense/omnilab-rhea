<?php

namespace Omnilab\Rhea;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rhea, the expert-curated knowledgebase of biochemical reactions (SIB,
 * the reactions of UniProtKB's catalytic activities): a reaction by any of
 * its four ids ("18157" the master, "18158" left to right, "18159" right
 * to left, "18160" both ways), or by its KEGG reaction ("R00200") -
 * its equation, status, balance, transport, its sides with their ChEBI
 * participants and coefficients, its EC numbers, GO term, citations; its
 * four ids and its KEGG reaction as identifiers; its ChEBI participants,
 * EC numbers, GO term, Reactome events and the reviewed UniProt proteins
 * that catalyse it as related things -; a search by text.
 *
 * entity() asks Rhea's SPARQL endpoint once (the reaction, its quartet,
 * its participants), then UniProt's REST API once for the reviewed
 * proteins (unless `enzymes` is 0); search() asks Rhea's REST search. No
 * key, no published rate; CC BY 4.0.
 */
final class RheaSource extends HttpSource
{
    public const BASE_URI = 'https://www.rhea-db.org/';
    public const SPARQL_URI = 'https://sparql.rhea-db.org/sparql';
    public const UNIPROT_URI = 'https://rest.uniprot.org/';

    /** The most reactions a search page holds. */
    public const MAX_LIMIT = 100;

    /** The fields of a reaction the query asks, by Rhea's predicate. */
    private const FIELDS = [
        'rh:accession' => 'accession', 'rh:equation' => 'equation', 'rh:status' => 'status', 'rh:isChemicallyBalanced' => 'balanced',
        'rh:isTransport' => 'transport', 'rh:ec' => 'ec', 'rh:citation' => 'citation', 'rdfs:seeAlso' => 'see_also',
        'rh:directionalReaction' => 'directional', 'rh:bidirectionalReaction' => 'bidirectional', 'rh:comment' => 'comment',
    ];

    /**
     * @param int $enzymes how many reviewed UniProt proteins entity() asks for (a second call, to UniProt); 0: none
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $enzymes = 25,
        string $baseUri = self::BASE_URI,
        private readonly string $sparqlUri = self::SPARQL_URI,
        private readonly string $uniprotUri = self::UNIPROT_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'rhea';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A reaction by a Rhea id ("18157", "RHEA:18160", its page) - a
     * direction's id gives the master reaction, which carries all four -
     * or by its KEGG reaction ("kegg.reaction:R00200", "rn:R00200");
     * another base's identifier is refused before any call; null when Rhea
     * has none.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::RHEA, Scheme::KEGG_REACTION) ?? throw NotSupportedException::identifier('rhea', (string) $id);
        $master = Scheme::RHEA === $identifier->scheme
            // The reaction itself when it is a master, else the master of which it is a direction.
            ? '{ VALUES ?m { rh:'.$identifier->value.' } ?m rdfs:subClassOf rh:Reaction } UNION { ?m rh:directionalReaction|rh:bidirectionalReaction rh:'.$identifier->value.' }'
            : '?m rdfs:subClassOf rh:Reaction . { ?m rdfs:seeAlso <http://identifiers.org/kegg.reaction/'.$identifier->value.'> } UNION { ?m rh:directionalReaction|rh:bidirectionalReaction ?k . ?k rdfs:seeAlso <http://identifiers.org/kegg.reaction/'.$identifier->value.'> }';
        $fields = implode(' ', array_map(static fn (string $p, string $f) => '('.$p.' "'.$f.'")', array_keys(self::FIELDS), self::FIELDS));
        $sparql = <<<SPARQL
            PREFIX rh:<http://rdf.rhea-db.org/>
            PREFIX rdfs:<http://www.w3.org/2000/01/rdf-schema#>
            SELECT ?m ?field ?value ?name ?order ?coefficient WHERE {
              { SELECT ?m WHERE { $master } }
              { VALUES (?p ?field) { $fields } ?m ?p ?value . }
              UNION { ?m rh:directionalReaction|rh:bidirectionalReaction ?q . ?q rdfs:seeAlso ?value . BIND("see_also" AS ?field) }
              UNION { ?m rh:side ?side . ?side rh:curatedOrder ?order ; ?c ?part . ?part rh:compound ?compound . ?compound rh:accession ?value .
                      FILTER(STRSTARTS(STR(?c), "http://rdf.rhea-db.org/contains") && ?c != rh:contains)
                      OPTIONAL { ?compound rh:name ?name }
                      BIND(STRAFTER(STR(?c), "contains") AS ?coefficient) BIND("participant" AS ?field) }
            }
            SPARQL;
        // No DISTINCT nor LIMIT in the sub-query: either takes Rhea's endpoint over a minute (2026-10-16), against 3 s.
        $rows = $this->getJson($this->sparqlUri, ['query' => (string) preg_replace('~\s+~', ' ', $sparql)])['results']['bindings'] ?? [];
        if (!$rows) {
            return null;
        }

        return $this->toEntity($rows);
    }

    /**
     * The reactions Rhea's search finds for the text (a name, a ChEBI id,
     * an EC number, "uniprot:P14618"...), in Rhea's order; a domain other
     * than chemistry answers nothing. An entity of a page holds its
     * equation, status, balance and participants: entity() reads it whole.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::CHEMISTRY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $offset = max(0, (int) $query->cursor);
        $limit = max(1, min(self::MAX_LIMIT, $query->limit));
        // One more than asked: whether a next page exists (Rhea's count is the page's, not the total).
        $results = $this->getJson('rhea', ['query' => $text, 'format' => 'json', 'limit' => $limit + 1, 'offset' => $offset])['results'] ?? [];
        $items = array_values(array_filter(array_map(fn (mixed $r) => \is_array($r) ? $this->fromSearch($r) : null, \array_slice($results, 0, $limit))));

        return new Page($items, \count($results) > $limit ? (string) ($offset + $limit) : null);
    }

    /** @param list<array<string, array{value: string}>> $rows the SPARQL bindings */
    private function toEntity(array $rows): ?Entity
    {
        $master = Identifier::tryOf(Scheme::RHEA, basename($rows[0]['m']['value'] ?? ''));
        if (null === $master) {
            return null;
        }
        $values = [];
        $sides = [];
        foreach ($rows as $row) {
            // One reaction: the first master found (a KEGG reaction is linked to one).
            if (($row['m']['value'] ?? null) !== $rows[0]['m']['value']) {
                continue;
            }
            $field = $row['field']['value'] ?? null;
            $value = $row['value']['value'] ?? null;
            if (null === $field || null === $value) {
                continue;
            }
            if ('participant' === $field) {
                $sides[(int) ($row['order']['value'] ?? 1)][$value] = [
                    'coefficient' => '1' === ($c = $row['coefficient']['value'] ?? '1') ? '' : $c.' ',
                    'name' => $row['name']['value'] ?? $value,
                ];
            } else {
                $values[$field][$value] = $value;
            }
        }
        $first = static fn (string $field): ?string => isset($values[$field]) ? (string) array_key_first($values[$field]) : null;
        $all = static fn (string $field): array => array_values($values[$field] ?? []);

        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'rhea');
            }
        };
        $add('status', 'Status', null === ($s = $first('status')) ? null : strtolower(basename($s)));
        $add('balanced', 'Chemically balanced', null === ($b = $first('balanced')) ? null : 'true' === $b);
        $add('transport', 'A transport reaction', null === ($t = $first('transport')) ? null : 'true' === $t);
        ksort($sides);
        $participants = [];
        foreach (array_values($sides) as $i => $side) {
            foreach ($side as $accession => $participant) {
                // "1 pyruvate (CHEBI:15361)"; a polymer or a generic compound keeps Rhea's accession (POLYMER:..., GENERIC:...).
                $add(0 === $i ? 'left' : 'right', 0 === $i ? 'Left side' : 'Right side', $participant['coefficient'].$participant['name'].' ('.$accession.')');
                $participants[] = Identifier::parse($accession);
            }
        }
        foreach ($all('comment') as $comment) {
            $add('comment', 'Comment', self::text($comment));
        }
        $add('references', 'Literature references', isset($values['citation']) ? \count($values['citation']) : null);
        foreach ($all('citation') as $citation) {
            $add('pubmed', 'PubMed', basename($citation));
        }

        $identifiers = [];
        foreach ([...$all('directional'), ...$all('bidirectional')] as $quartet) {
            $identifiers[] = Identifier::tryOf(Scheme::RHEA, basename($quartet));
        }
        $related = ['participant' => new Identifiers(array_filter($participants, static fn (?Identifier $i) => Scheme::CHEBI === $i?->scheme))];
        $enzymes = array_map(static fn (string $ec) => Identifier::tryOf(Scheme::EC, basename($ec)), $all('ec'));
        $related['enzyme'] = new Identifiers($enzymes);
        $events = [];
        $go = [];
        foreach ($all('see_also') as $link) {
            match (true) {
                (bool) preg_match('~/kegg\.reaction/(R\d{5})$~', $link, $m) => $identifiers[] = Identifier::tryOf(Scheme::KEGG_REACTION, $m[1]),
                (bool) preg_match('~/reactome/(R-[A-Z]{3}-\d+)~', $link, $m) => $events[] = Identifier::tryOf(Scheme::REACTOME, $m[1]),
                (bool) preg_match('~/GO_(\d{7})$~', $link, $m) => $go[] = Identifier::tryOf(Scheme::GO, $m[1]),
                // MetaCyc, EcoCyc, M-CSA: bases the core has no scheme for.
                (bool) preg_match('~identifiers\.org/(?:biocyc/)?([^/]+)$~', $link, $m) => $add('xref', 'Other database', $m[1]),
                default => null,
            };
        }
        $related['event'] = new Identifiers($events);
        $related['go'] = new Identifiers($go);

        $proteins = [];
        if ($this->enzymes > 0) {
            $ids = array_map(static fn (?Identifier $i) => $i?->value, [$master, ...array_filter($identifiers, static fn (?Identifier $i) => Scheme::RHEA === $i?->scheme)]);
            $query = '('.implode(' OR ', array_map(static fn (?string $id) => 'cc_catalytic_activity:"rhea:'.$id.'"', array_filter($ids))).') AND reviewed:true';
            // UniProt compresses twice when asked gzip alone: identity, as omnilab/uniprot asks.
            [$data, $headers] = $this->getJsonWithHeaders(rtrim($this->uniprotUri, '/').'/uniprotkb/search', ['query' => $query, 'fields' => 'accession', 'size' => min(500, $this->enzymes), 'format' => 'json'], ['Accept-Encoding' => 'identity']) ?? [[], []];
            foreach ($data['results'] ?? [] as $result) {
                $proteins[] = Identifier::tryOf(Scheme::UNIPROT, $result['primaryAccession'] ?? null);
            }
            $add('enzymes', 'Reviewed UniProt proteins that catalyse it', isset($headers['x-total-results'][0]) ? (int) $headers['x-total-results'][0] : null);
        }
        $related['protein'] = new Identifiers($proteins);

        $equation = self::plain($first('equation'));

        return new Entity(
            id: $master,
            label: $equation ?? $master->display(),
            domain: Domain::CHEMISTRY,
            kind: 'reaction',
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: $master->url(),
            source: 'rhea',
            related: array_filter($related, static fn (Identifiers $i) => \count($i) > 0),
        );
    }

    /** An equation, its white space collapsed: not HTML ("<=>" is a direction). */
    private static function plain(?string $text): ?string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', (string) $text));

        return '' === $text ? null : $text;
    }

    /** @param array<string, mixed> $result a search result */
    private function fromSearch(array $result): ?Entity
    {
        $id = Identifier::tryOf(Scheme::RHEA, (string) ($result['id'] ?? ''));
        if (null === $id) {
            return null;
        }
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'rhea');
            }
        };
        $add('status', 'Status', \is_string($result['status'] ?? null) ? strtolower($result['status']) : null);
        $add('balanced', 'Chemically balanced', isset($result['balanced']) ? (bool) $result['balanced'] : null);
        $add('transport', 'A transport reaction', isset($result['transport']) ? (bool) $result['transport'] : null);
        // The HTML equation names each participant: data-molid="chebi:15361".
        preg_match_all('~data-molid="chebi:(\d+)"~', (string) ($result['htmlequation'] ?? ''), $m);

        return new Entity(
            id: $id,
            label: self::plain($result['equation'] ?? null) ?? $id->display(),
            domain: Domain::CHEMISTRY,
            kind: 'reaction',
            description: self::text($result['comment'] ?? null),
            properties: $properties,
            url: $id->url(),
            source: 'rhea',
            related: $m[1] ? ['participant' => new Identifiers(array_map(static fn (string $c) => Identifier::tryOf(Scheme::CHEBI, $c), $m[1]))] : [],
        );
    }
}
