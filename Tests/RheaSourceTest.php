<?php

namespace Omnilab\Rhea\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Rhea\RheaSource;
use Omnilab\Rhea\RheaSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded on 2026-10-16: Rhea's SPARQL answer for RHEA:18160
 * (pyruvate kinase's reaction both ways, whose master is 18157 - the
 * same answer for its KEGG reaction R00200) and for an unknown id; the
 * reviewed UniProt proteins that catalyse it (25 of 111, UniProt
 * 2026_03); Rhea's search for "pyruvate kinase" in two pages of three,
 * and for a text nothing matches.
 */
final class RheaSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): RheaSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $host = (string) parse_url($url, \PHP_URL_HOST);
            $this->calls[] = $host.' '.($query['query'] ?? '');
            if ($response) {
                return $response;
            }
            [$fixture, $headers] = match (true) {
                'sparql.rhea-db.org' === $host && (str_contains($query['query'], 'rh:18160 }') || str_contains($query['query'], 'kegg.reaction/R00200>')) => ['sparql-18160.json', []],
                'sparql.rhea-db.org' === $host => ['sparql-99999.json', []],
                'rest.uniprot.org' === $host => ['uniprot-rhea-18157.json', ['x-total-results' => '111']],
                'pyruvate kinase' === ($query['query'] ?? null) => ['0' === $query['offset'] ? 'search-pyruvate-kinase-0.json' : 'search-pyruvate-kinase-3.json', []],
                default => ['search-notfound.json', []],
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['response_headers' => $headers]);
        });
        $source = (new RheaSourceFactory($http))->create($options);
        self::assertInstanceOf(RheaSource::class, $source);

        return $source;
    }

    public function testADirectionGivesItsMasterReactionInTwoCalls(): void
    {
        $reaction = $this->source()->entity('RHEA:18160');

        self::assertCount(2, $this->calls);
        self::assertStringStartsWith('sparql.rhea-db.org ', $this->calls[0]);
        self::assertStringContainsString('cc_catalytic_activity:"rhea:18157" OR cc_catalytic_activity:"rhea:18158" OR cc_catalytic_activity:"rhea:18159" OR cc_catalytic_activity:"rhea:18160") AND reviewed:true', $this->calls[1]);
        self::assertSame('rhea:18157', $reaction->id->key());
        self::assertSame('RHEA:18157', $reaction->id->display());
        self::assertSame('pyruvate + ATP = phosphoenolpyruvate + ADP + H(+)', $reaction->label);
        self::assertSame(Domain::CHEMISTRY, $reaction->domain);
        self::assertSame('reaction', $reaction->kind);
        self::assertSame('https://www.rhea-db.org/rhea/18157', $reaction->url);
        self::assertSame('approved', $reaction->value('status'));
        self::assertTrue($reaction->value('balanced'));
        self::assertFalse($reaction->value('transport'));
        self::assertSame(['pyruvate (CHEBI:15361)', 'ATP (CHEBI:30616)'], array_map('strval', $reaction->properties('left')));
        self::assertSame(['phosphoenolpyruvate (CHEBI:58702)', 'ADP (CHEBI:456216)', 'H(+) (CHEBI:15378)'], array_map('strval', $reaction->properties('right')));
        self::assertSame(11, $reaction->value('references'));
        self::assertCount(11, $reaction->properties('pubmed'));
        self::assertSame(['ECOCYC:PEPDEPHOS-RXN', 'METACYC:PEPDEPHOS-RXN'], array_map('strval', $reaction->properties('xref')));
        self::assertSame(111, $reaction->value('enzymes'));
        self::assertSame(['rhea:18158', 'rhea:18159', 'rhea:18160', 'kegg.reaction:R00200'], $reaction->identifiers->keys(), 'its directions and its KEGG reaction: the same reaction');
        self::assertSame(['15361', '30616', '58702', '456216', '15378'], $reaction->related('participant')->values(Scheme::CHEBI));
        self::assertSame(['2.7.1.40'], $reaction->related('enzyme')->values(Scheme::EC));
        self::assertSame(['R-HSA-71670'], $reaction->related('event')->values(Scheme::REACTOME));
        self::assertSame(['0004743'], $reaction->related('go')->values(Scheme::GO));
        self::assertCount(25, $reaction->related('protein'));
        self::assertSame('P30613', $reaction->related('protein')->value(Scheme::UNIPROT));
    }

    public function testByItsKeggReactionAndWithoutUniProt(): void
    {
        $reaction = $this->source(options: ['enzymes' => 0])->entity(Identifier::of(Scheme::KEGG_REACTION, 'R00200'));

        self::assertCount(1, $this->calls);
        self::assertStringContainsString('<http://identifiers.org/kegg.reaction/R00200>', $this->calls[0]);
        self::assertSame('rhea:18157', $reaction->id->key());
        self::assertNull($reaction->value('enzymes'));
        self::assertCount(0, $reaction->related('protein'));
    }

    public function testUnknownIsNullOtherBasesRefusedBeforeAnyCall(): void
    {
        self::assertNull($this->source()->entity('99999'));
        self::assertCount(1, $this->calls);
        $this->calls = [];
        foreach (['pyruvate kinase', 'CHEBI:15361', 'EC 2.7.1.40', 'kegg:C00022'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail($id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchInPages(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('pyruvate kinase', limit: 3));

        self::assertSame(['www.rhea-db.org pyruvate kinase'], $this->calls);
        self::assertSame(['rhea:11364', 'rhea:18157', 'rhea:23052'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame('3', $page->next);
        self::assertNull($page->total);
        $first = $page->items[1];
        self::assertSame('pyruvate + ATP = phosphoenolpyruvate + ADP + H(+)', $first->label);
        self::assertSame('approved', $first->value('status'));
        self::assertSame(['15361', '30616', '58702', '456216', '15378'], $first->related('participant')->values(Scheme::CHEBI));

        $next = $source->search(new Query('pyruvate kinase', limit: 3, cursor: $page->next));
        self::assertCount(3, $next);
        self::assertTrue($next->isLast());
        self::assertCount(0, $source->search(new Query('zzqqxx')));
        self::assertCount(0, $source->search(new Query('pyruvate kinase', Domain::ASTRONOMY)));
    }

    public function testAServiceDownAndCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        self::assertSame('rhea', (new RheaSourceFactory())->getName());
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('18157');
    }
}
