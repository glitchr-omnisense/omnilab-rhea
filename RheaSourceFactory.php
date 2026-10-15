<?php

namespace Omnilab\Rhea;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Rhea: biochemical reactions by Rhea or KEGG id, their ChEBI
 * participants, EC numbers and the UniProt proteins that catalyse them;
 * a search - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: Rhea asks callers for a contact address
 *     enzymes: 25                      # reviewed UniProt proteins entity() asks for (a call to UniProt); 0: none
 *     throttle: 0                      # seconds between two calls: Rhea publishes no rate
 *     base_uri: 'https://www.rhea-db.org/'
 *     sparql_uri: 'https://sparql.rhea-db.org/sparql'
 *     uniprot_uri: 'https://rest.uniprot.org/'
 */
final class RheaSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'rhea',
            'omnilab.required_options' => [],
            'enzymes' => 25,
            'throttle' => 0.0,
            'base_uri' => RheaSource::BASE_URI,
            'sparql_uri' => RheaSource::SPARQL_URI,
            'uniprot_uri' => RheaSource::UNIPROT_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new RheaSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('enzymes'),
            (string) $c->get('base_uri', RheaSource::BASE_URI),
            (string) $c->get('sparql_uri', RheaSource::SPARQL_URI),
            (string) $c->get('uniprot_uri', RheaSource::UNIPROT_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
