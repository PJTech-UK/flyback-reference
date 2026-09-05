# Where the data came from, and what you may do with it

The code in this repository is MIT-licensed. The reference data did not
originate with this project, is not licensed here, and no rights over it are
asserted. Sources are credited below. Requests from rights holders to remove
specific material will be actioned.

## Why there is no CC0 on the data

A public-domain dedication is a grant of rights, and only the rights holder can
make one. Almost none of this reference data originated with this project: it was
published over several decades by manufacturers and by hobbyists. Applying CC0 to
it would assert a right this project does not hold, and would lead others to rely
on a licence that was never valid.

The position is therefore stated directly:

- **This project asserts no copyright and no database right over the reference
  data in this repository, and grants no licence to it, because it holds
  neither.** Anything this project genuinely authored — the extraction code, the
  schematic renderer, the search application, the analysis and documentation —
  is MIT-licensed and yours to use.
- Individual facts — that part X is cross-referenced to part Y, that pin 2 is
  B+, that a tester recorded 26.3 kV — are generally not subject to copyright.
  Compilations of such facts may attract a separate database right in the EU and
  UK, which runs for a fixed term from the making or last substantial update of
  the database. The oldest material here dates from 2003.
- **This is not legal advice.** Take your own before building anything
  commercial on this data.

## Sources, and how they are credited

Every record carries a source tag, and the interface displays it. Inferred
links are marked as inferred and are not merged into manufacturer claims.

| tag | what it is |
|---|---|
| *(default)* | A manufacturer's reference database of part descriptors, cross-references, fitment lists and application notes, compiled 2003. |
| `site` | The same manufacturer's public catalogue pages, captured before the site went offline. |
| `xref` | Their published 2011 equivalence catalogue. |
| `classic` | A second aftermarket manufacturer's catalogues, used only to *infer* links, tagged distinctly and never presented as the first manufacturer's claim. |
| `datapin` | Community-compiled pin-out documents, shared on hobbyist forums. Watermarks are left intact and the origin travels with each row. |

Archived copies of pages come from the Internet Archive and Common Crawl.

## Attribution

Much of the reference data originates with **HR Diemen**, a Spanish manufacturer
of replacement flyback transformers whose catalogue and technical listings were
published for the trade over many years, and whose website is now offline.

**HR Diemen is a trademark of Efiter S.L. This project is not affiliated with,
endorsed by, sponsored by, or connected to Efiter or HR Diemen in any way, and
makes no claim to their trademarks.** The name appears here as attribution and as
a factual statement of where information came from. Nothing in this archive
represents the manufacturer's current position, and the archive is not a source
of parts, warranty or support.

Community-contributed pin-out material carries watermarks from sites that are now
defunct. Those watermarks are preserved.

## If you hold rights in any of this

Open an issue, or contact the repository owner through GitHub. A specific
request to remove specific material will be actioned without requiring formal
process.

## What this data is not

It is not evidence that any two parts interchange. A published cross-reference
is a statement that a manufacturer sold one part for another's application. It
was produced by a company reverse-engineering other manufacturers' transformers,
and is a commercial claim rather than a measurement.

Inductance, DC resistance and inter-winding capacitance determine whether a
flyback will work in a given circuit. None of those values were published by any
of the sources used here. Some figures in this dataset were read by machine from
scanned drawings and contain recognition errors.
