# Security policy

## Supported versions

Only the latest release receives fixes. Older tags are not patched.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it through GitHub's private advisory form:
[Report a vulnerability](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/security/advisories/new)

Or email the maintainer at the address on <https://www.frederickmadore.com/>.

Please include the module version, the Omeka S and PHP versions, and enough detail to
reproduce. You will get an acknowledgement within a week; this is a research-infrastructure
project maintained alongside other work, so please allow reasonable time for a fix before
disclosing publicly.

## Scope

This module publishes the public part of an Omeka S database as RDF: per-resource documents
at `/resource/{id}.*`, a full dump at `/linked-data/dump.nt.gz`, and a dataset description at
`/linked-data/void`. Its whole job is deciding what is public, so the most serious findings
are disclosures:

- A private item, item set or media — or a private value on a public one — appearing in a
  resource document, the dump or the dataset description
- A link that reveals the existence or id of a private resource
- A value of an excluded term (`dre:mongoId`, `dre:security`, `dre:bitstream`) being published
- An uploaded file's original name or a server path leaking through a media's `source`
- Injection through metadata into the RDF serialisations or the page block's HTML
- Privilege issues in the module configuration form or the dump job

The dump is written under Omeka's `files/` directory and is public by design, as are the
figures in `state.json` beside it.

Vulnerabilities in Omeka S core belong to the [Omeka project](https://omeka.org/s/).
