# Hunting service manuals for parts we know nothing about

995 HR codes have a usage list but no technical data. A set's service manual
contains the LOPT pin-out and its rail voltages, so the sets a part was fitted
to are a way back in.

```bash
python3 extract/service_manual_worklist.py                 # build the worklist
python3 extract/service_manual_worklist.py --show "HR 80914"
python3 extract/service_manual_worklist.py --found "HR 80914" \
    --confidence hit --chassis "11AK56" --url "https://..." --note "..."
python3 extract/service_manual_worklist.py --status
php bin/build-db.php                               # leads appear on the cards
```

## Three things that decide the hit rate

**Search by chassis, not by model.** Manuals are archived by chassis. Philips
22 K 201, 26 C 465 and 26 K 209 are all chassis K9, and Elektrotanya files the
manual under K9. This is what makes the job finite: the ~8,900 models implicated
collapse to a few hundred chassis.

**Our own data often names the chassis already.** 256 of the 995 codes have a
usage entry like `PHILIPS CHASIS A 8.0 A` or `VESTEL CHASIS 11 AK 56` — step one
already done, and those convert at a much higher rate.

**Work newest-first, not most-used-first.** The tempting order is by usage
count, and it is wrong: the most-fitted parts are HR 51 and HR 2xxx, in 1970s
valve-era sets whose manuals are the rarest and worst-scanned material online.
Higher HR numbers are later parts in better-documented sets. Ordering by HR
number descending turned a run of failures into a run of hits.

Ranking within a code is by model distinctiveness. "BANG & OLUFSEN B&O 3500"
returns a turntable, a speaker and a music centre, because 3500 is four
characters B&O reused across product lines; the full `BEOVISION 3500` resolves.
Short numeric models are tried last.

Stopping rule is per HR code: work its models in order, stop at the first manual
found, move on.

## Confidence is recorded, not implied

| level | meaning |
|---|---|
| `hit` | chassis matches something in our own usage list, and the manual is reachable |
| `lead` | plausible, unverified — right family, or a manual whose scope is unconfirmed |
| `weak` | sibling model or partial match only |

**Nothing here has been read.** These are pointers to documents, produced by
search; the PDFs are old scans and need human eyes. The UI says so on every card.

Be sceptical of search summaries in particular. One claimed a ManualsLib manual
for Goldstar chassis MC-049A while the actual result was a different model
(CB-14A80); that is recorded as `weak` with the discrepancy noted rather than as
a find.

## Results so far

12 codes attempted, 5 `hit`, 3 `lead`, 4 `weak`. The hits:

| HR | chassis | source |
|---|---|---|
| HR 2031/22 | Philips K9 | elektrotanya, several K9 manuals |
| HR 80880 | Philips A8.0A / A8.0A-AA | elektrotanya, multiple variants |
| HR 80914 | Vestel 11AK56 / 11AK56-4 | elektrotanya + electronica-pt |
| HR 81193 | Thomson R4000 / EUROCOMBO | eserviceinfo (covers R4000-R7000, T4000-T7000) |
| HR 82430 | Hitachi M3-LXU | elektrotanya |

Chassis-named European codes convert best. US-market sets (RCA, Magnavox,
Quasar) are largely SAMS Photofact territory — indexed but paid, so they come
back as `lead` at best.

## Where the effort should go next

The 256 chassis-named codes, newest first, are the productive seam. After that,
codes whose usage lists contain Philips / Grundig / Thomson / Vestel / ITT
models, which the free European archives cover well.

`extract/service_manual_worklist.csv` holds ready-made queries for every
remaining code, ordered as described above.
