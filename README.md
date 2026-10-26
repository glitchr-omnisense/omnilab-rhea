# omnilab/rhea

Rhea, the expert-curated knowledgebase of biochemical reactions (SIB), for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a reaction by any of its four ids -
the master `18157`, its directions `18158`, `18159` and both ways `18160` - or by its KEGG reaction
(`R00200`): its equation, status, balance, whether it is a transport, its two sides with their
participants and coefficients, its citations; its four ids and its KEGG reaction as identifiers;
its ChEBI participants, EC numbers, GO term, Reactome events and the reviewed UniProt proteins that
catalyse it as related things; a search by text. No key.

```php
$rhea = (new RheaSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$reaction = $rhea->entity('RHEA:18160');           // or '18157', 'rn:R00200', its page
$reaction->id->display();                          // RHEA:18157: the master of 18160
$reaction->label;                                  // pyruvate + ATP = phosphoenolpyruvate + ADP + H(+)
$reaction->properties('left');                     // pyruvate (CHEBI:15361), ATP (CHEBI:30616)
$reaction->identifiers->keys();                    // rhea:18158, rhea:18159, rhea:18160, kegg.reaction:R00200
$reaction->related('participant')->keys();         // chebi:15361, chebi:30616, chebi:58702, chebi:456216, chebi:15378
$reaction->related('enzyme')->keys();              // ec:2.7.1.40
$reaction->value('enzymes');                       // 111 reviewed UniProt proteins; the first 25 in related('protein')
$rhea->search(new Query('pyruvate kinase', limit: 3));
```

```yaml
omnilab:
    sources:
        rhea:
            factory: rhea
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent: Rhea asks callers for a contact address
                enzymes: 25                    # reviewed UniProt proteins entity() asks UniProt for; 0: none
```

With omnilab/kegg configured too, the `Collector` walks from a KEGG reaction into Rhea and back
(each names the other: `DBLINKS RHEA: 18160`, Rhea's link to `kegg.reaction/R00200`).

## Terms of use

Rhea is under the [Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/)
licence: free to copy, distribute and use commercially, crediting Rhea
([License & Disclaimer](https://www.rhea-db.org/help/license-disclaimer)); its data are "for
research, educational and informational purposes only", not for medical use. Rhea asks programs
to send a contact address in their User-Agent (`mailto`). Cite Rhea as its help pages say ("How to
cite us"). UniProt's answers (the proteins) are under CC BY 4.0 too.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the SIB Swiss Institute of Bioinformatics (Rhea). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License (of this package's code): MIT.
