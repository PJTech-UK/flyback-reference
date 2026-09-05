/* Flyback, LOPT & Tripler Cross-Reference Database — front-end client.
 * Talks to the PHP API (/api/catalog, /api/search). Renders result cards from
 * the hydrated objects the API returns (no client-side dataset). Provides the
 * advanced text search, a guided query builder, and a help modal. */
(() => {
  "use strict";

  const $ = id => document.getElementById(id);
  const esc = s => (s == null ? "" : String(s)).replace(/[&<>"']/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));

  const $q = $("q"), $cat = $("category"), $only = $("onlyImgs"), $uses = $("searchUses"),
        $sortBy = $("sortBy"),
        $results = $("results"), $summary = $("summary"), $pager = $("pager"),
        $modal = $("modal"), $modalImg = $("modalImg");

  let CATALOG = null;
  let factorMap = {};
  let page = 1;

  // Fixed assumed CRT anode voltage for the Uf estimate. The per-search selector
  // was removed (it only affected bleeder-equipped parts); 24 kV is the default.
  const DEFAULT_EHT = 24;
  const currentEht = () => DEFAULT_EHT;

  // ---- Uf voltage model (mirrors the API; used for the per-card estimate) ----
  function ufRange(r, ehtKv, externalPot) {
    if (!r || r.top == null || r.bot == null) return null;
    const Rlow = r.top, Rup = r.bot;
    const P = externalPot != null ? externalPot : (r.pot != null ? r.pot : 0);
    if (P <= 0) {
      const uf = ehtKv * Rlow / (Rlow + Rup);
      return { min: uf, max: uf, nominal: uf, withPot: false };
    }
    const total = Rlow + Rup + P;
    return { min: ehtKv * Rlow / total, max: ehtKv * (Rlow + P) / total,
             nominal: ehtKv * (Rlow + P / 2) / total, withPot: true };
  }

  // ---------------------------------------------------------------- rendering
  function openImage(src) { $modalImg.src = src; $modal.classList.add("show"); }
  $modal.addEventListener("click", () => $modal.classList.remove("show"));

  function renderImages(imgs) {
    if (!imgs || imgs.length === 0) return "";
    const parts = imgs.map(im => im.path.toLowerCase().endsWith(".pdf")
      ? `<figure><a href="${esc(im.path)}" target="_blank">${esc(im.kind)} (PDF) ▸</a></figure>`
      : `<figure><img loading="lazy" data-src="${esc(im.path)}" alt="${esc(im.kind)}"><figcaption>${esc(im.kind)}</figcaption></figure>`);
    return `<div class="img-row">${parts.join("")}</div>`;
  }

  function renderTester(row) {
    if (row.mat_kv == null) return "";
    const pinBC = `${row.pinB ? `<span class="pin b">B<span class="label">${esc(row.pinB)}</span></span>` : ""}${row.pinC ? `<span class="pin c">C<span class="label">${esc(row.pinC)}</span></span>` : ""}`;
    const pinD = (row.pinsD || []).map(p => `<span class="pin d">D<span class="label">${esc(p)}</span></span>`).join("");
    const defl = row.alim_or_deflection ? `<span class="pin x">∡<span class="label">${esc(row.alim_or_deflection)}</span></span>` : "";
    return `<div class="tester"><div class="mat">${row.mat_kv.toFixed(1)} <small>kV MAT (STVDST tester)</small></div><div class="pinrow">${pinBC}${pinD}${defl}</div></div>`;
  }

  function renderSchemData(s) {
    if (!s) return "";
    const polGlyph = p => p === "positive" ? "∩" : p === "negative" ? "∪" : "·";
    const vppRows = (s.vpps || []).slice().sort((a, b) => (b.v || 0) - (a.v || 0)).map(v => `
      <div class="vpp-row">
        <span class="vpp-v">${v.v != null ? v.v + " V" : "?"}</span>
        <span class="vpp-pol" title="${v.pol || "unknown polarity"}">${polGlyph(v.pol)}</span>
        <span class="vpp-pin">${v.pin ? "pin " + esc(v.pin) : "—"}</span>
        <span class="vpp-side">${v.side === "L" ? "left" : v.side === "R" ? "right" : ""}</span>
      </div>`).join("");
    const range = (s.v_min != null && s.v_max != null && s.v_min !== s.v_max) ? `${s.v_min}–${s.v_max} V`
      : (s.v_max != null ? `${s.v_max} V` : "—");
    const rolesLine = (s.roles || []).length
      ? `<div class="sch-roles">${s.roles.map(r => `<span class="role-chip">${esc(r)}</span>`).join("")}</div>` : "";
    return `<div class="sch-data">
        <div class="sch-meta">
          <span><b>${s.n_wnd ?? "?"}</b> windings</span>
          ${s.n_seg ? `<span><b>${s.n_seg}</b> segments</span>` : ""}
          <span>range <b>${range}</b></span>
          ${s.pol_pos ? `<span title="positive ∩">+${s.pol_pos}</span>` : ""}
          ${s.pol_neg ? `<span title="negative ∪">−${s.pol_neg}</span>` : ""}
        </div>
        ${rolesLine}
        ${vppRows ? `<div class="vpp-list">${vppRows}</div>` : `<div class="nodata">No Vpp annotations extracted.</div>`}
      </div>`;
  }

  function renderResistors(hr, code) {
    const r = hr;
    if (!r || r.total == null) return "";
    const eht = currentEht();
    const cells = [];
    if (r.total != null) cells.push(`<div><b>Total</b><br><span class="rval">${r.total} MΩ</span></div>`);
    if (r.top   != null) cells.push(`<div><b>R<sub>lower</sub></b><br><span class="rval">${r.top} MΩ</span></div>`);
    if (r.bot   != null) cells.push(`<div><b>R<sub>upper</sub></b><br><span class="rval">${r.bot} MΩ</span></div>`);
    if (r.pot   != null) cells.push(`<div><b>Pot</b><br><span class="rval">${r.pot} MΩ</span></div>`);
    let note = "";
    if (r.top != null && r.bot != null && r.total != null) {
      const sum = r.top + r.bot;
      const ok = Math.abs(sum - r.total) <= Math.max(2, r.total * 0.05);
      note = `<div class="rcheck" style="font-size:11px;color:${ok ? "#080" : "#c40"}">lower + upper = ${sum} ${ok ? "✓" : "≠ total — re-check"}</div>`;
    }
    const heading = r.verified
      ? `<h3>Internal bleeder network <small style="color:#080;font-weight:normal">✓ verified</small></h3>`
      : `<h3>Internal bleeder network <small style="color:#888;font-weight:normal">— OCR, unverified</small></h3>`;
    let ufBlock = "";
    const uf = ufRange(r, eht, null);
    if (uf) {
      const ufText = uf.withPot
        ? `<span class="uf-range">${uf.min.toFixed(2)} – ${uf.max.toFixed(2)} kV</span> <span class="uf-nom">(mid ≈ ${uf.nominal.toFixed(2)} kV)</span>`
        : `<span class="uf-range">${uf.nominal.toFixed(2)} kV</span> <span class="uf-nom">(fixed, no pot)</span>`;
      let whatIf = "";
      if (!uf.withPot) {
        const opts = [22, 33, 47, 100].map(p => {
          const u = ufRange(r, eht, p);
          return `<span class="uf-whatif">+${p} MΩ pot → ${u.min.toFixed(2)} – ${u.max.toFixed(2)} kV</span>`;
        }).join("");
        whatIf = `<div class="uf-whatif-row"><b>If an external pot is added:</b> ${opts}</div>`;
      }
      const groupBtns = `<div class="uf-actions">
          <button class="link-btn" data-action="same-network" data-hr="${esc(code)}">same network</button>
          <button class="link-btn" data-action="similar-uf" data-hr="${esc(code)}">similar Uf range</button>
        </div>`;
      ufBlock = `<div class="ufblock">
          <div class="uf-line"><b>Estimated U<sub>f</sub> @ ${eht} kV EHT:</b> ${ufText}</div>
          ${whatIf}${groupBtns}
          <div class="rcaption" style="margin-top:4px">Assumes simple-divider topology with pot in series. Not valid for HRTs that tap off intermediate C-W stages.</div>
        </div>`;
    }
    return `${heading}<div class="resblock"><div class="rgrid">${cells.join("")}</div>${note}
        <div class="rcaption">${r.verified ? "values curated from the schematic." : "read by OCR — spot-check against the diagram before trusting it for repair work."}</div>
        ${ufBlock}</div>`;
  }

  function renderBox(row) {
    const b = row.box;
    if (b) {
      return `<div class="img-row"><figure>
          <img loading="lazy" data-src="${esc(b.image)}" alt="box ${esc(row.box_class)}">
          <figcaption>${esc(row.box_class)} · ${b.x_cm}×${b.y_cm}×${b.z_cm} cm${row.weight_g ? ` · ${row.weight_g} g` : ""}</figcaption>
        </figure></div>`;
    }
    if (row.dim || row.weight_g) {
      const d = row.dim;
      const dimText = d ? `${d.x_cm}×${d.y_cm}×${d.z_cm} cm` : "";
      const wtText = row.weight_g ? `${row.weight_g} g` : "";
      const sep = (dimText && wtText) ? " · " : "";
      return `<div class="nodata" style="color:var(--fg);font-style:normal">${dimText}${sep}${wtText}</div>`;
    }
    return row.box_class ? `<div class="nodata">Box ${esc(row.box_class)} (dimensions unknown)</div>` : "";
  }

  function renderNotes(note, row) {
    const internalish = /\bINTERNAL/i.test(note);
    const pinsD = (row.pinsD || []).map(p => String(p).replace(/^0+/, "")).filter(Boolean);
    const caveat = internalish && pinsD.length
      ? `<div class="note-caveat">⚠ The manufacturer's tester data lists ground pin(s) <b>${pinsD.join(", ")}</b> for this HR — typically these ARE the external ground tie shown on the schematic, so the "connected internally" claim may be HR Diemen's own boilerplate rather than a literal statement about this part.</div>` : "";
    return `<blockquote class="notes">${esc(note)}</blockquote>${caveat}`;
  }

  function renderUses(uses) {
    if (!uses || uses.length === 0) return `<div class="nodata">No TV/monitor usage recorded.</div>`;
    const grouped = new Map();
    for (const u of uses) {
      if (!grouped.has(u.fabname)) grouped.set(u.fabname, []);
      grouped.get(u.fabname).push(u);
    }
    // Brands containing a match float to the top: the list runs to 2,000 models
    // on the big triplers, and scrolling to find why the part matched is no use.
    const rows = [...grouped.entries()]
      .sort((a, b) => (b[1].some(u => u.hit) - a[1].some(u => u.hit))
                   || String(a[0]).localeCompare(b[0]))
      .map(([fab, us]) => `<div class="fab${us.some(u => u.hit) ? " hit" : ""}">${esc(fab)}</div>` +
        `<div class="models">${us.map(u =>
          `<span${u.hit ? ' class="hit"' : ""}>${esc(u.model || "(unspecified)")}</span>`).join("")}</div>`).join("");
    return `<div class="uses-grid">${rows}</div>`;
  }

  /* The set the search actually named, shown without expanding anything.
   * Searching CM8833 used to return three correct parts with no sign anywhere
   * on the card of what a CM8833 was — the term only existed inside a collapsed
   * list. Up to eight are named; beyond that the count carries it. */
  function renderUseHits(uses) {
    const hits = (uses || []).filter(u => u.hit);
    if (!hits.length) return "";
    const shown = hits.slice(0, 8)
      .map(u => `<span class="use-hit">${esc(((u.fabname || "") + " " + (u.model || "")).trim())}</span>`)
      .join("");
    const more = hits.length > 8 ? `<span class="use-more">+ ${hits.length - 8} more</span>` : "";
    return `<div class="use-hits">${shown}${more}</div>`;
  }

  /* Candidate substitutes: parts whose extracted tap profile is the same shape
   * at a comparable scale, in the same line-rate class. Deliberately not called
   * equivalents — HR Diemen never claimed these interchange, and inductance,
   * DCR and stray capacitance, which decide it, were never published. This is
   * what the schematic data above is *for*: on its own it is a readout, and it
   * only becomes useful when something compares it with another part's. */
  function renderSubs(subs, code) {
    if (!subs || !subs.n) return "";
    const pct = d => (d * 100).toFixed(1) + "%";
    const rows = (subs.top || []).map(s => {
      const bits = [];
      bits.push(s.pins
        ? `<span class="sub-tag good" title="Same B+ and collector pins — no rewiring">same pins</span>`
        : `<span class="sub-tag" title="B+ or collector sits on a different pin — viable only if you will move wires">rewire</span>`);
      if (!s.mat_ok) bits.push(`<span class="sub-tag bad" title="Anode rating below this part's. It may pass on the bench and flash over in a month.">MAT ${s.mat ?? "?"} kV</span>`);
      if (s.missing) bits.push(`<span class="sub-tag" title="Taps this part has that the candidate does not">${s.missing} tap${s.missing === 1 ? "" : "s"} short</span>`);
      if (s.extra) bits.push(`<span class="sub-tag" title="Spare taps you can leave unconnected">+${s.extra} spare</span>`);
      const scale = Math.abs(s.scale - 1) < 0.005 ? "same B+"
        : `B+ ×${s.scale.toFixed(2)}`;
      return `<div class="sub-row">
        <button class="link-btn" data-goto="${esc(s.code)}">${esc(s.code)}</button>
        <span class="sub-fit" title="Mean difference between the two normalised tap profiles">Δ${pct(s.d)}</span>
        <span class="sub-scale" title="Anode voltage scales by this factor too — the CRT decides whether that is survivable">${scale}</span>
        ${bits.join("")}
      </div>`;
    }).join("");
    const more = subs.n > (subs.top || []).length
      ? `<button class="link-btn" data-subs="${esc(code)}">see all ${subs.n}</button>` : "";
    return `<h3>Possible substitutes (${subs.n})
        <small style="color:#888;font-weight:normal">— shape-matched shortlist, <b>not</b> equivalents</small></h3>
      <div class="subs">${rows}${more ? `<div class="sub-row">${more}</div>` : ""}</div>
      <div class="sub-note">Matched on: same line-rate class and deflection angle, tap profile within
        12%, and an implied B+ rescale between ×0.80 and ×1.25 — the anode voltage scales by the same
        factor. Inductance, DC resistance and inter-winding capacitance are not accounted for; those
        values are not in this dataset.</div>`;
  }

  /* Functional pin labels (ABL / HTR / AFC / B+ …) from the Data-Pin
   * compilations. HR never published these. Where two compilations describe the
   * same part differently, both are shown and the disagreement is called out —
   * silently picking one would be inventing confidence we do not have. */
  function renderPinFunc(groups) {
    if (!groups || !groups.length) return "";
    const sig = g => g.pins.map(p => `${p.pin}=${p.fn}`).join(" ");
    const conflict = groups.length > 1 && new Set(groups.map(sig)).size > 1;
    const srcName = s => s === "datapin_a" ? "Data-Pin (text)" : "Data-Pin (illustrated)";
    const blocks = groups.map(g => `
      <div class="pf-block">
        <div class="pf-head">stated for <b>${esc(g.oem)}</b>
          <small>— ${esc(srcName(g.src))}</small></div>
        <div class="pf-pins">${g.pins.map(p => `
          <span class="pf-pin"><b>${p.pin}</b>${esc(p.fn)}${p.v != null ? "" : ""}</span>`).join("")}</div>
      </div>`).join("");
    const warn = conflict
      ? `<div class="pf-warn">These sources disagree about this part. Both are shown as published;
         neither has been verified against a physical sample.</div>` : "";
    return `<div class="pinfunc">${warn}${blocks}</div>`;
  }

  /* Service-manual leads for parts we hold no data on. Nobody has opened these
   * PDFs, so each carries its confidence openly: a chassis that matches our own
   * usage list is a different proposition from a same-numbered sibling model. */
  function renderManualLeads(list) {
    if (!list || !list.length) return "";
    const label = { hit: "chassis match", lead: "unverified lead", weak: "weak / sibling model" };
    const rows = list.map(m => `
      <li class="ml-${esc(m.confidence)}">
        <span class="ml-badge">${esc(label[m.confidence] || m.confidence)}</span>
        ${m.chassis ? `chassis <b>${esc(m.chassis)}</b> — ` : ""}
        ${m.url ? `<a href="${esc(m.url)}" target="_blank" rel="noopener noreferrer">manual</a>` : ""}
        <div class="ml-note">${esc(m.note)}</div>
      </li>`).join("");
    return `<ul class="manual-leads">${rows}</ul>
      <div class="nodata">Found by searching the sets this part was fitted to. Not yet read
      or checked against the part — a service manual gives the LOPT pin-out, which is the
      point, but it needs human eyes.</div>`;
  }

  function renderCard(res) {
    const row = res.row, code = res.code;
    // The API returns {oem, src, alts}; tolerate the older bare-string shape too.
    // One chip per part: `alts` holds the other spellings of the same number
    // (AT 2075-30102 / AT 2075/30102), which used to be a chip each.
    const equivs = (res.equivs || []).map(e => (typeof e === "string" ? { oem: e, src: null } : e));
    const matched = new Set(res.matched || []);
    const nXref = equivs.filter(e => e.src === "xref").length;
    const nClassic = equivs.filter(e => e.src === "classic").length;
    const provBits = [
      nXref && `${nXref} from the 2011 catalogue`,
      nClassic && `${nClassic} inferred via Classic`,
    ].filter(Boolean);
    const provNote = provBits.length
      ? ` <small style="color:#888;font-weight:normal">— ${provBits.join(", ")}</small>` : "";
    const imgs = res.images || [], sch = res.sch, uses = res.uses || [], accs = res.acc || [];
    const familyImg = row.family_image
      ? `<div class="img-row"><figure><img loading="lazy" data-src="${esc(row.family_image)}" alt="${esc(row.family)}"><figcaption>family ${esc(row.family)}</figcaption></figure></div>` : "";
    const meta = [
      row.tester_type && `<span><b>${esc(row.tester_type)}</b></span>`,
      row.family && `<span>family <b>${esc(row.family)}</b></span>`,
      sch && sch.n_wnd && `<span><b>${sch.n_wnd}</b> windings</span>`,
      sch && sch.v_max != null && `<span>up to <b>${sch.v_max} V</b></span>`,
      row.weight_g && `<span><b>${row.weight_g}</b> g</span>`,
      row.box_class && `<span>box <b>${esc(row.box_class)}</b></span>`,
      uses.length && `<span><b>${uses.length}</b> known uses</span>`,
    ].filter(Boolean).join("");
    // Do we actually know anything about this part, beyond its name?
    const hasData = !!(row.tester_type || row.mat_kv != null || row.weight_g ||
                       sch || uses.length || imgs.length);

    const srcBadge = row.source === "site_2012"
      ? `<span class="src-badge" title="Post-2003 record scraped from hrdiemen.com (May 2012-vintage data)">2012</span>`
      : hasData ? ""
      : row.listed
      ? `<span class="src-badge listed" title="HR Diemen published a catalogue page for this code, but it carried no technical data">listed, no data</span>`
      : `<span class="src-badge xref" title="Known only from the 2011 HR Diemen equivalence catalogue">cross-ref only</span>`;

    // A part we can name but know nothing about. Say so plainly rather than
    // showing a card that reads like a failed lookup — and distinguish "the
    // manufacturer definitely sold this" from "a cross-reference table
    // mentions it", because that changes how hard it is worth hunting for.
    const ghostNote = hasData ? "" : row.listed
      ? `<div class="nodata ghost">
           <b>Known part, missing record.</b> HR Diemen listed this code in their own catalogue —
           so it is a real product — but the page carried no tester reading, schematic or fitment list,
           and the site is now offline. The equivalents on the right are what we have.
         </div>`
      : `<div class="nodata ghost">
           <b>Cross-reference only.</b> This code appears in HR Diemen's 2011 equivalence catalogue,
           but we hold no technical data for it and it was not among the catalogue pages recovered
           from their site. The equivalents on the right are still the useful part — one of them
           may be obtainable even when this one is not.
         </div>`;

    return `<div class="card">
        <h2>${esc(code)} ${srcBadge}</h2>
        <div class="meta">${meta}</div>
        <div class="layout">
          <div>
            ${ghostNote}
            <h3>Schematic</h3>
            ${imgs.length ? renderImages(imgs) : `<div class="nodata">No schematic on disk for this part.</div>`}
            ${row.mat_kv != null ? `<h3>Flyback tester (STVDST)</h3>${renderTester(row)}` : ""}
            ${(res.pinfunc || []).length ? `<h3>Pin functions <small style="color:#888;font-weight:normal">— from community Data-Pin compilations, not HR Diemen</small></h3>${renderPinFunc(res.pinfunc)}` : ""}
            ${(res.manuals || []).length ? `<h3>Service manuals for sets using this part</h3>${renderManualLeads(res.manuals)}` : ""}
            ${sch ? `<h3>Schematic data <small style="color:#888;font-weight:normal">— OCR-extracted; what the substitute search below matches on</small></h3>${renderSchemData(sch)}` : ""}
            ${renderSubs(res.subs, code)}
            ${renderResistors(res.hrt_r, code)}
            ${(row.box || row.box_class || familyImg) ? `<h3>Packaging &amp; shape</h3>` : ""}
            ${renderBox(row)}${familyImg}
          </div>
          <div>
            <h3>Manufacturer equivalents (${equivs.length})${provNote}</h3>
            <div class="equivs">${equivs.map(e => {
              const alts = e.alts || [];
              const cls = "equiv" + (matched.has(e.oem) ? " hit" : "") +
                          (e.src === "xref" ? " xref" : e.src === "classic" ? " classic" : "") +
                          (alts.length ? " variants" : "");
              const t = (e.src === "xref"
                ? "From the 2011 HR Diemen equivalence catalogue"
                : e.src === "classic"
                ? "Inferred: Classic list this code against an FBT part that corresponds to this HR part. Not an HR Diemen claim."
                : "From the manufacturer's 2003 reference database")
                + (alts.length
                   ? `\n\nAlso printed as: ${alts.join(", ")}\nPunctuation is ignored when searching, so any of these finds it.`
                   : "");
              return `<span class="${cls}" title="${esc(t)}">${esc(e.oem)}</span>`;
            }).join("")}</div>
            ${window.Sourcing ? Sourcing.render(res) : ""}
            <p class="permalink"><a href="/part/${esc(code.replace(/[^A-Za-z0-9]+/g, "").toLowerCase())}">Permanent page for ${esc(code)}</a></p>
            ${res.obs ? `<h3>Notes <small style="color:#888;font-weight:normal">— quoted verbatim from the manufacturer's own application notes; not independently verified</small></h3>${renderNotes(res.obs, row)}` : ""}
            ${accs.length ? `<h3>Accessories</h3><ul class="accs">${accs.map(a => `<li>${esc(a)}</li>`).join("")}</ul>` : ""}
            ${uses.length ? (() => {
              const nHit = uses.filter(u => u.hit).length;
              // uses_total is the real figure; the array may be truncated for
              // parts fitted to thousands of sets. Matches are never truncated.
              const total = res.uses_total ?? uses.length;
              const head = `<h3>Used in (${total.toLocaleString()})${nHit
                ? ` <small style="color:#8a6d00;font-weight:normal">— ${nHit} match${nHit === 1 ? "" : "es"} your search</small>` : ""}</h3>`;
              const shown = uses.length < total
                ? `Show ${uses.length.toLocaleString()} of ${total.toLocaleString()} TV / monitor models`
                : `${nHit ? "Show all " : "Show "}${total.toLocaleString()} TV / monitor model${total === 1 ? "" : "s"}`;
              const note = uses.length < total
                ? `<div class="nodata" style="font-size:11px">Long list truncated. Every model matching your search is shown; search a model name to find a specific set.</div>`
                : "";
              // Open the list when the search named a set in it, so the reason
              // this part came back is on screen without a click.
              return head + renderUseHits(uses) +
                `<details${nHit ? " open" : ""}><summary>${shown}</summary>${renderUses(uses)}${note}</details>`;
            })() : ""}
          </div>
        </div>
      </div>`;
  }

  const io = new IntersectionObserver(entries => {
    for (const e of entries) {
      if (e.isIntersecting) { e.target.src = e.target.dataset.src; io.unobserve(e.target); }
    }
  }, { rootMargin: "300px" });

  // ------------------------------------------------------------------ search
  let timer = null;
  function scheduleSearch(resetPage = true) {
    if (resetPage) page = 1;
    clearTimeout(timer);
    timer = setTimeout(runSearch, 180);
  }

  async function runSearch() {
    const q = $q.value;
    syncUrl();
    if (q.trim() === "" && !$cat.value) {
      $summary.innerHTML = "";
      $pager.innerHTML = "";
      $results.innerHTML = `<div class="empty">Type a part code above, or click <b>Compose a search</b>. Whitespace, dashes and case are ignored.<br>Search manufacturer codes, HR codes, TV/monitor models, or by focus voltage (e.g. <code>uf:6-8</code>), Vpp, winding count, tester type and more — combine them with <code>AND</code>, <code>OR</code>, <code>NOT</code> and parentheses.</div>`;
      return;
    }
    const params = new URLSearchParams({
      q, page: String(page), sort: $sortBy.value, eht: String(currentEht()),
      onlyImgs: $only.checked ? "1" : "0", uses: $uses.checked ? "1" : "0",
    });
    if ($cat.value) params.set("category", $cat.value);
    let data;
    try {
      const resp = await fetch("/api/search?" + params.toString());
      data = await resp.json();
    } catch (e) {
      $results.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
      return;
    }
    renderResultsPage(data);
  }

  /* A type filter only sees the parts whose tester type was recorded, which is
   * about a quarter of them — the rest arrived through cross-reference
   * catalogues carrying no descriptors. Without saying so, filtering to
   * "monitor" returns 239 and reads as "this archive has almost no monitor
   * parts" rather than "this archive does not know the type of most parts". */
  function typeFilterNote() {
    const untyped = (CATALOG && CATALOG.stats && CATALOG.stats.untyped) || 0;
    if (!untyped) return "";
    const filtered = $cat.value || /\b(type|tester_type):/i.test($q.value);
    if (!filtered) return "";
    return ` <span class="note-inline">${untyped.toLocaleString()} parts have no type` +
           ` recorded and are not included — <button class="linkish" data-drop-type>search` +
           ` without the type filter</button>.</span>`;
  }

  function renderResultsPage(data) {
    if (data.empty || data.total === 0) {
      $summary.textContent = data.empty ? "" : "No matches.";
      $pager.innerHTML = "";
      // A near-miss on a brand name is offered, never applied: the search itself
      // stays exact, so a result count is never a guess. See src/Suggest.php.
      const sg = data.suggestion;
      const sug = sg
        ? `<div class="did-you-mean">Did you mean <button class="link-btn" data-suggest="${esc(sg.query)}">${esc(sg.query)}</button>?</div>`
        : "";
      $results.innerHTML = data.empty ? ""
        : `<div class="empty">No HR parts match that search.${sug}</div>`;
      for (const btn of $results.querySelectorAll("[data-suggest]")) {
        btn.addEventListener("click", () => { $q.value = btn.dataset.suggest; scheduleSearch(); });
      }
      return;
    }
    $summary.innerHTML = `${data.total.toLocaleString()} HR part${data.total === 1 ? "" : "s"} match` +
      (data.pages > 1 ? ` · page ${data.page} of ${data.pages}` : "") + "." + typeFilterNote();
    $results.innerHTML = data.results.map(renderCard).join("");
    for (const img of $results.querySelectorAll("img[data-src]")) {
      io.observe(img);
      img.addEventListener("click", () => openImage(img.dataset.src || img.src));
    }
    for (const btn of $summary.querySelectorAll("[data-drop-type]")) {
      btn.addEventListener("click", () => {
        $cat.value = "";
        const rest = $q.value.replace(/\b(?:tester_)?type:\S+\s*/gi, "").trim();
        // If the type filter was the whole query, removing it would leave the
        // empty "type a part code" state, which answers nothing. data:any is
        // the match-all, so the point being made — there are more parts than
        // the filter showed — is actually visible.
        $q.value = rest || "data:any";
        scheduleSearch();
      });
    }
    for (const btn of $results.querySelectorAll("[data-goto]")) {
      btn.addEventListener("click", () => {
        $q.value = btn.dataset.goto;
        scheduleSearch();
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    }
    for (const btn of $results.querySelectorAll("[data-subs]")) {
      btn.addEventListener("click", () => {
        $q.value = `subs:"${btn.dataset.subs}"`;
        scheduleSearch();
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    }
    for (const btn of $results.querySelectorAll(".link-btn[data-action]")) {
      btn.addEventListener("click", () => {
        const hr = btn.dataset.hr;
        const tok = btn.dataset.action === "same-network" ? "network" : "similaruf";
        $q.value = `${tok}:"${hr}"`;
        scheduleSearch();
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    }
    renderPager(data);
  }

  function renderPager(data) {
    if (data.pages <= 1) { $pager.innerHTML = ""; return; }
    $pager.innerHTML = `
      <button class="btn" id="prevPage" ${data.page <= 1 ? "disabled" : ""}>‹ Prev</button>
      <span class="pinfo">page ${data.page} / ${data.pages} · ${data.total.toLocaleString()} results</span>
      <button class="btn" id="nextPage" ${data.page >= data.pages ? "disabled" : ""}>Next ›</button>`;
    const go = d => { page = data.page + d; runSearch(); window.scrollTo({ top: 0, behavior: "smooth" }); };
    if ($("prevPage")) $("prevPage").onclick = () => go(-1);
    if ($("nextPage")) $("nextPage").onclick = () => go(1);
  }

  // --------------------------------------------------------------- URL sync
  function syncUrl() {
    const p = new URLSearchParams();
    if ($q.value.trim()) p.set("q", $q.value.trim());
    if ($cat.value) p.set("category", $cat.value);
    if ($sortBy.value && $sortBy.value !== "code") p.set("sort", $sortBy.value);
    if ($only.checked) p.set("onlyImgs", "1");
    if (!$uses.checked) p.set("uses", "0");
    if (page > 1) p.set("page", String(page));
    const qs = p.toString();
    history.replaceState(null, "", qs ? "?" + qs : location.pathname);
  }
  function restoreFromUrl() {
    const p = new URLSearchParams(location.search);
    if (p.has("q")) $q.value = p.get("q");
    if (p.has("category")) $cat.value = p.get("category");
    if (p.has("sort")) $sortBy.value = p.get("sort");
    if (p.has("onlyImgs")) $only.checked = p.get("onlyImgs") === "1";
    if (p.has("uses")) $uses.checked = p.get("uses") !== "0";
    if (p.has("page")) page = Math.max(1, parseInt(p.get("page"), 10) || 1);
  }

  // ------------------------------------------------------------- query builder
  //
  // The builder used to make you do the parser's job: every row carried an
  // unlabelled "(" checkbox at one end and a ")" checkbox at the other, and
  // "+ ( group )" pushed two rows with one bracket each. You had to hold the
  // nesting in your head and tick it in by hand.
  //
  // It is groups now. A group is a box of conditions with one connector —
  // match ALL of these, or ANY of these — and the brackets are implied by the
  // box. Groups are joined to each other the same way. Nothing to balance, and
  // "+ group" visibly adds a box rather than two mysterious half-rows.
  const $builder = $("builder"), $brows = $("brows"), $bpreview = $("bpreview");
  let groups = [];

  // No field is chosen to start with. The composer used to default every row to
  // the free-text factor, whose token is deliberately bare — so an untouched row
  // contributed a plain word, which is exactly what the search box above already
  // does, and read as the composer ignoring the field you thought you had
  // picked. A row now contributes nothing until you say what it is about.
  function defaultCond() {
    return { factorKey: "", op: "", val: "", a: "", b: "", neg: false };
  }
  function defaultGroup() { return { joiner: "AND", match: "AND", conds: [defaultCond()] }; }

  function factorByKey(k) { return factorMap[k]; }

  function tokenFor(r) {
    if (!r.factorKey) return "";
    const f = factorByKey(r.factorKey); if (!f) return "";
    const op = f.ops.find(o => o.op === r.op) || f.ops[0];
    // Quote multi-word values except for the general free-text factor (token "{v}"),
    // which is meant to match an unquoted phrase. Prefixed factors (make:, model:…) need quotes.
    const quote = v => (/\s/.test(v) && (f.type !== "text" || op.token !== "{v}")) ? `"${v}"` : v;
    let tok;
    if (op.token.includes("{a}")) {
      const a = (r.a ?? "").toString().trim(), b = (r.b ?? "").toString().trim();
      if (a === "" || b === "") return "";
      tok = op.token.replace("{a}", a).replace("{b}", b);
    } else {
      const v = (r.val ?? "").toString().trim();
      if (v === "") return "";
      tok = op.token.replace("{v}", quote(v));
    }
    return r.neg ? "NOT " + tok : tok;
  }

  function composeQuery() {
    const parts = [];
    for (const g of groups) {
      const toks = g.conds.map(tokenFor).filter(t => t !== "");
      if (!toks.length) continue;
      // Brackets only where they change the reading — a lone condition needs none.
      const body = toks.length > 1 ? `(${toks.join(` ${g.match} `)})` : toks[0];
      if (parts.length) parts.push(g.joiner);
      parts.push(body);
    }
    return parts.join(" ");
  }

  /* The catalogue's examples are whole query tokens — `make:sony`, `model:kt2345`
   * — because they are also printed in the help table, where the prefix is the
   * point. In the composer the prefix is added for you, so showing it in the box
   * invites you to type it twice. Strip whatever the op's token puts in front. */
  function placeholderFor(f, op) {
    if (f.type === "hr") return "HR 7002";
    let eg = (f.examples && f.examples[0]) || "";
    // The example is written for one operator (`uf:6-8`) but the row may be on
    // another (`uf>=`), so strip the factor key and whatever separator follows
    // rather than the current op's exact prefix.
    eg = eg.replace(new RegExp("^" + f.key + "\\s*[:<>=]+\\s*", "i"), "");
    eg = eg.replace(/^"|"$/g, "");
    // `uf:6-8` is a valid value for the "is" operator but not for ">=", so trim a
    // range example to its first number only for the comparison operators. Text
    // factors are left alone: `19293-134` is a part number, not a range.
    if (/[<>]/.test(String(op.token))) {
      const m = eg.match(/^(-?\d+(?:\.\d+)?)\s*-\s*-?\d/);
      if (m) eg = m[1];
    }
    return eg;
  }

  function valueControl(r, f) {
    const op = f.ops.find(o => o.op === r.op) || f.ops[0];
    if (op.token.includes("{a}")) {
      return `<input class="bval ba" type="number" step="any" placeholder="min" value="${esc(r.a)}">
              <input class="bval bb" type="number" step="any" placeholder="max" value="${esc(r.b)}">`;
    }
    if (f.type === "enum" || f.type === "bool") {
      const opts = (f.options || []).map(o => `<option value="${esc(o.value)}" ${r.val === o.value ? "selected" : ""}>${esc(o.label)}</option>`).join("");
      return `<select class="bval">${`<option value="">—</option>` + opts}</select>`;
    }
    const type = f.type === "number" ? "number" : "text";
    return `<input class="bval" type="${type}" ${type === "number" ? 'step="any"' : ""} placeholder="${esc(placeholderFor(f, op))}" value="${esc(r.val)}">`;
  }

  function renderBuilder() {
    const totalConds = groups.reduce((n, g) => n + g.conds.length, 0);
    $brows.innerHTML = "";
    groups.forEach((g, gi) => {
      const box = document.createElement("div");
      box.className = "bgroup";
      box.dataset.g = gi;

      const between = gi === 0
        ? `<span class="bgroup-lead">Find parts where</span>`
        : `<select class="bjoiner" title="How this group combines with the ones above">
             ${["AND", "OR"].map(o => `<option value="${o}" ${g.joiner === o ? "selected" : ""}>${o === "AND" ? "and also" : "or else"}</option>`).join("")}
           </select>`;
      // The match selector only means anything with something to match between.
      const match = g.conds.length > 1
        ? `<select class="bmatch">
             ${["AND", "OR"].map(o => `<option value="${o}" ${g.match === o ? "selected" : ""}>${o === "AND" ? "all of these are true" : "any of these are true"}</option>`).join("")}
           </select>`
        : `<span class="bgroup-lead">this is true</span>`;
      const dropGroup = groups.length > 1
        ? `<button class="bx bx-group" title="Remove this group">×</button>` : "";

      const conds = g.conds.map((r, ci) => {
        const f = factorByKey(r.factorKey);
        const factorSel = `<select class="bfactor">`
          + `<option value="" ${r.factorKey ? "" : "selected"}>— choose a field —</option>`
          + CATALOG.factors.map(ff => `<option value="${ff.key}" ${ff.key === r.factorKey ? "selected" : ""}>${esc(ff.label)}</option>`).join("")
          + `</select>`;
        // × only where removing something is meaningful: never on the last
        // condition of the last group, because deleting it just puts it back.
        const drop = totalConds > 1
          ? `<button class="bx" title="Remove this condition">×</button>` : "";
        const rest = f
          ? `<select class="bop">${f.ops.map(o => `<option value="${esc(o.op)}" ${o.op === r.op ? "selected" : ""}>${esc(o.label)}</option>`).join("")}</select>`
            + valueControl(r, f)
          : `<span class="bwaiting">pick what this condition is about</span>`;
        const not = f
          ? `<label class="bnot" title="Exclude parts that match this condition">
               <input type="checkbox" class="bneg" ${r.neg ? "checked" : ""}> not
             </label>`
          : `<span class="bnot-spacer"></span>`;
        return `<div class="brow" data-c="${ci}">${not}${factorSel}${rest}${drop}</div>`;
      }).join("");

      box.innerHTML = `<div class="bgroup-head">${between}${match}${dropGroup}</div>
        ${conds}
        <button class="btn bsmall badd-cond" type="button">+ condition</button>`;
      $brows.appendChild(box);
    });
    $bpreview.textContent = composeQuery() || "(nothing to search for yet)";
  }

  function readCond(rowEl) {
    const gi = +rowEl.closest(".bgroup").dataset.g, ci = +rowEl.dataset.c;
    const r = groups[gi].conds[ci];
    r.factorKey = rowEl.querySelector(".bfactor").value;
    const f = factorByKey(r.factorKey);
    if (!f) { r.op = ""; return; }
    const opEl = rowEl.querySelector(".bop"); if (opEl) r.op = opEl.value;
    if (!f.ops.find(o => o.op === r.op)) r.op = f.ops[0].op;
    const ba = rowEl.querySelector(".ba"), bb = rowEl.querySelector(".bb");
    const bv = rowEl.querySelector(".bval:not(.ba):not(.bb)");
    if (ba) r.a = ba.value;
    if (bb) r.b = bb.value;
    if (bv && !ba) r.val = bv.value;
    const negEl = rowEl.querySelector(".bneg");
    r.neg = negEl ? negEl.checked : false;
  }

  function onBuilderInput(e) {
    const box = e.target.closest(".bgroup"); if (!box) return;
    const gi = +box.dataset.g;
    if (e.target.classList.contains("bjoiner")) { groups[gi].joiner = e.target.value; }
    else if (e.target.classList.contains("bmatch")) { groups[gi].match = e.target.value; }
    else {
      const rowEl = e.target.closest(".brow"); if (!rowEl) return;
      readCond(rowEl);
    }
    // Changing the factor or operator changes which controls belong on the row.
    if (e.target.classList.contains("bfactor") || e.target.classList.contains("bop")) renderBuilder();
    else $bpreview.textContent = composeQuery() || "(nothing to search for yet)";
  }
  $brows.addEventListener("input", onBuilderInput);
  $brows.addEventListener("change", onBuilderInput);

  $brows.addEventListener("click", e => {
    const box = e.target.closest(".bgroup"); if (!box) return;
    const gi = +box.dataset.g;
    if (e.target.classList.contains("badd-cond")) {
      groups[gi].conds.push(defaultCond());
    } else if (e.target.classList.contains("bx-group")) {
      groups.splice(gi, 1);
    } else if (e.target.classList.contains("bx")) {
      const ci = +e.target.closest(".brow").dataset.c;
      groups[gi].conds.splice(ci, 1);
      if (!groups[gi].conds.length) groups.splice(gi, 1);
    } else return;
    if (!groups.length) groups = [defaultGroup()];
    renderBuilder();
  });

  $("addGroup").onclick = () => { groups.push(defaultGroup()); renderBuilder(); };
  $("applyBuilder").onclick = () => {
    const q = composeQuery();
    if (q) { $q.value = q; $builder.hidden = true; scheduleSearch(); }
  };
  $("toggleBuilder").onclick = () => {
    $builder.hidden = !$builder.hidden;
    if (!$builder.hidden && !groups.length) { groups = [defaultGroup()]; renderBuilder(); }
  };
  $("builderClose").onclick = () => { $builder.hidden = true; };

  // ----------------------------------------------------------------- help
  function buildHelp() {
    const rowsHtml = CATALOG.factors.map(f => {
      const toks = f.ops.map(o => `<span class="help-tok">${esc(o.token.replace("{v}", "…").replace("{a}", "lo").replace("{b}", "hi"))}</span>`).join(" ");
      const egs = (f.examples || []).map(e => `<code>${esc(e)}</code>`).join(" ");
      return `<tr><td><b>${esc(f.label)}</b><br><span class="help-eg">${esc(f.help || "")}</span></td><td>${toks}<br><span class="help-eg">${egs}</span></td></tr>`;
    }).join("");
    $("helpBody").innerHTML = `
      <p>Type freely — manufacturer codes, HR codes and TV/monitor model names all match, and spaces, dashes and case are ignored. Or click <b>Compose a search</b> to build one from menus.</p>
      <h3>Combine conditions</h3>
      <p>Join conditions with <span class="help-tok">AND</span>, <span class="help-tok">OR</span> and <span class="help-tok">NOT</span>, and group them with parentheses. Two conditions next to each other are ANDed automatically.</p>
      <p>Examples:<br>
        <code>type:SM AND (vpp&gt;=200 OR role:focus)</code><br>
        <code>sony NOT type:CH</code><br>
        <code>(uf:6-8 OR uf&gt;=7) AND img:yes</code></p>
      <h3>Factors</h3>
      <table class="help-table"><thead><tr><th>What</th><th>How to type it</th></tr></thead><tbody>${rowsHtml}</tbody></table>
      <p style="margin-top:14px" class="help-eg">The focus-voltage (Uf) estimate assumes a <b>24 kV</b> CRT anode (EHT) supply and a simple-divider bleeder; it isn't valid for HRTs that tap intermediate multiplier stages.</p>`;
  }
  const openAbout = () => $("about").classList.add("show");
  // Clear resets the query, the filters and the sort — "reset" is what people
  // actually want when a search has gone somewhere odd, and clearing only the
  // text box leaves a category filter quietly applied.
  const $clear = $("clearBtn");
  function syncClear() {
    $clear.hidden = !($q.value || $cat.value || $only.checked ||
                      !$uses.checked || $sortBy.value !== "code");
  }
  $clear.onclick = () => {
    $q.value = ""; $cat.value = ""; $only.checked = false;
    $uses.checked = true; $sortBy.value = "code";
    page = 1;
    syncClear();
    $q.focus();
    runSearch();
  };
  for (const el of [$q, $cat, $only, $uses, $sortBy]) {
    el.addEventListener("input", syncClear);
    el.addEventListener("change", syncClear);
  }

  $("aboutBtn").onclick = openAbout;
  if ($("aboutBtn2")) $("aboutBtn2").onclick = openAbout;
  $("aboutClose").onclick = () => { $("about").classList.remove("show"); };
  $("about").addEventListener("click", e => { if (e.target.id === "about") $("about").classList.remove("show"); });
  $("helpBtn").onclick = () => { $("help").classList.add("show"); };
  $("helpClose").onclick = () => { $("help").classList.remove("show"); };
  $("help").addEventListener("click", e => { if (e.target.id === "help") $("help").classList.remove("show"); });

  // ----------------------------------------------------------------- init
  async function init() {
    CATALOG = await (await fetch("/api/catalog")).json();
    factorMap = Object.fromEntries(CATALOG.factors.map(f => [f.key, f]));
    $cat.innerHTML = (CATALOG.categories || [{ value: "", label: "Any type" }])
      .map(c => `<option value="${esc(c.value)}">${esc(c.label)}</option>`).join("");
    $sortBy.innerHTML = CATALOG.sorts.map(s => `<option value="${s.value}">${esc(s.label)}</option>`).join("");
    const st = CATALOG.stats || {};
    const n = v => (v || 0).toLocaleString();
    $("stats").textContent =
      `${n(st.parts)} transformer parts · ${n(st.codes)} manufacturer part numbers · ` +
      `${n(st.models)} TV and monitor models`;
    // Belongs here, after the fetch. Reading CATALOG at module top level threw
    // on a null and took every handler registered below it with it.
    if ($("version") && CATALOG.version) {
      $("version").textContent = "v" + CATALOG.version
        + (CATALOG.generated ? " · data built " + CATALOG.generated.slice(0, 10) : "");
    }
    buildHelp();

    restoreFromUrl();
    syncClear();
    // Deep links: #build opens the query builder, #help opens the help modal.
    if (location.hash === "#build") { $builder.hidden = false; groups = [defaultGroup()]; renderBuilder(); }
    if (location.hash === "#help") $("help").classList.add("show");
    if (location.hash === "#about") $("about").classList.add("show");
    $q.addEventListener("input", () => scheduleSearch());
    $cat.addEventListener("change", () => scheduleSearch());
    $only.addEventListener("change", () => scheduleSearch());
    $uses.addEventListener("change", () => scheduleSearch());
    $sortBy.addEventListener("change", () => scheduleSearch());
    document.addEventListener("keydown", e => {
      if (e.key === "Escape") { $modal.classList.remove("show"); $("help").classList.remove("show"); $("about").classList.remove("show"); }
    });
    runSearch();
  }
  init();
})();
