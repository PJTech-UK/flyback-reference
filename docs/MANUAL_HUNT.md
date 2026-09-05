# Service-manual leads

995 parts have a usage list but no technical data. A set's service manual carries
the transformer pin-out and its rail voltages, so the sets a part was fitted to
are a route back in. `manuals` in the database holds what has been found.

**Nothing here has been read.** These are pointers produced by search; the PDFs
are old scans and need human eyes. The interface says so on every card.

| confidence | meaning |
|---|---|
| `hit` | chassis matches this dataset's own usage list, and the manual is reachable |
| `lead` | plausible, unverified — right family, or a manual of unconfirmed scope |
| `weak` | sibling model or partial match only |

## What decides the hit rate

**Search by chassis, not model.** Manuals are archived by chassis. Philips
22 K 201, 26 C 465 and 26 K 209 are all chassis K9. That collapses ~8,900
implicated models to a few hundred chassis.

256 of the 995 codes already name a chassis in their usage list
(`PHILIPS CHASIS A 8.0 A`, `VESTEL CHASIS 11 AK 56`) and convert at a much higher
rate.

**Work newest-first.** Ordering by usage count is wrong: the most-fitted parts
are 1970s valve-era, whose manuals are the rarest and worst-scanned material
online. Higher part numbers are later sets, better documented.

Within a code, rank by model distinctiveness. "BANG & OLUFSEN B&O 3500" returns a
turntable, a speaker and a music centre; `BEOVISION 3500` resolves. Short numeric
models last.

## Results so far

12 codes attempted: 5 `hit`, 3 `lead`, 4 `weak`. Chassis-named European codes
convert best. US-market sets are largely SAMS Photofact territory — indexed but
paid, so `lead` at best.
