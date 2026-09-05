# Substitutes: method and limits

A shortlist, not a compatibility verdict. The interface calls these "similar devices",
keeps them collapsed, and states before the toggle that they are not compatible
types — only parts that may share enough characteristics to be worth trying when
the exact part cannot be found.

## What can be compared

Published, therefore comparable: the pin map (`pinB` B+/Vcc, `pinC` collector,
`pinsD` returns), peak-to-peak pulse amplitude per pin, output roles (H.V.,
FOCUS, G2, Vcc), maximum anode rating, family and physical format.

**Never published, and decisive:** inductance, DC resistance, inter-winding
capacitance, saturation behaviour. A flyback sits in a tuned circuit and the
primary inductance sets the retrace time, so two parts with identical published
figures can behave differently.

## Two traps when reading Vpp

**Vpp is not a DC rail.** Manufacturer datasheets list rectified outputs; this
data lists peak-to-peak pulse amplitudes. A flyback secondary is a narrow retrace
pulse near baseline for most of the line period, so peak-rectifying it recovers
close to the full Vpp less a diode drop. Neither halving nor doubling converts
one to the other.

**Vpp is tester-referenced.** The figures come off the manufacturer's own rig
under its own excitation, not a live chassis. They are consistent within this
dataset, so part-to-part comparison is sound; converting them to in-service rail
voltages needs drive conditions that were never published.

## Matching

Raw voltages match almost nothing — two flybacks are rarely identical. What they
can be is the same design at a different scale: every winding is ratiometric to
the primary, so normalising each part's taps to its own largest tap makes two
parts comparable when no single voltage matches.

Filters, in order, over the 2,503 parts with two or more extracted taps:

| filter | pairs | parts served |
|---|---:|---:|
| same tester class | 5,449,172 | 2,503 |
| + tap profile within 12% | 221,502 | 2,483 |
| + implied B+ rescale ×0.80–×1.25 | 122,592 | 2,450 |
| + same deflection angle / scan band | 61,514 | **2,373** |
| + anode rating not below the target's | 33,249 | 2,211 |
| + same B+ and collector pins | 4,168 | 1,154 |
| + missing none of the target's taps | 508 | **357** |

Class comes first: a 15.6 kHz TV flyback is not a candidate for a multiscan
monitor at any voltage.

The first four are stored in the `substitutes` table; anode rating and pin
identity are recorded as flags rather than filtered, so a part needing only its
wires moved is offered and marked, not hidden.

**EHT scales with B+.** A candidate needing ×0.80 to bring its auxiliary rails
into line drops the anode voltage by a fifth, and the CRT decides whether that is
survivable. ×0.80–×1.25 is already generous.

## Fan-in

A part sold as the equivalent of 65 different manufacturer numbers is a coarser
claim than one sold for two. High fan-in is a prompt to check the drawing
yourself, not proof of a bad match.

## Data-quality caveats

- Only parts with extracted schematic data can be matched.
- **Polarity is not reliable per-image.** Globally the split is healthy (~4.4k
  positive / 3.7k negative) but individual parts extract wrongly. Check against
  the drawing.
- Some parts record two taps against one pin — sometimes a genuinely tapped
  winding, sometimes mis-association by the reader.
- Pin labels are zero-padded inconsistently between sources; compare numerically.
