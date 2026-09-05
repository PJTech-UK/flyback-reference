# A second manufacturer's catalogue

`dataset/classic_oem.csv`, from two catalogues by "Classic", another aftermarket
flyback maker: an OEM-code index (54pp) and a brand-and-model index (101pp).

Classic appear to be defunct and their parts are almost never offered for sale,
so the stock value is nil. The linkage value is not.

## Two independent bridges

**Via OEM codes.** 7,804 manufacturer part numbers appear in both catalogues.
Where both firms claim a part for the same original number, they
reverse-engineered the same transformer. That links 1,025 of 1,071 Classic parts
to at least one HR part; 837 resolve to exactly one.

**Via TV models.** Independently, 6,558 brand+model strings appear in both
Classic's by-model index and the HR fitment table, linking 540 Classic parts.

Where both bridges exist for the same part (513 cases) they agree on at least one
HR part **96.3%** of the time. Two vendors, two different join keys, same answer
— which is what justifies admitting transitive links at all.

## Terms

If a Classic part corresponds to exactly one HR part, every other OEM code
Classic lists against it becomes a candidate equivalent. This adds **1,006
pairs**, tagged `src='classic'`.

- **Unambiguous only.** A Classic part resolving to more than one HR part is
  dropped, not guessed at.
- **Never laundered.** Tagged and rendered distinctly, with a tooltip saying the
  original manufacturer did not make this claim.
- **Never overrides.** Existing pairs win; only genuinely new pairs are added.

Of 1,689 OEM codes Classic knows and HR does not, 1,509 reach an HR part this
way.

**Compounding error.** An equivalence is already a sales claim rather than a
measurement. A Classic-inferred link is a sales claim chained to another, so its
looseness compounds. Treat `src='classic'` as a lead to investigate, and prefer
the shape matching in `SUBSTITUTES.md` when the question is whether a part will
work rather than what else it is called.

## Parsing note

Both catalogues repeat their column group across the page and their left-hand
fields contain internal single spaces (`01 004 10`, `CTV 5197`), so whitespace
tokenising destroys them. The parser anchors on the `FBT\d+` codes and takes the
text preceding each, splitting on runs of two or more spaces. `TUN*` tuner
numbers are filtered — they are not transformers.
