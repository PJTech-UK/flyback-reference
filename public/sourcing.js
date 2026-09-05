/* sourcing.js — "find this part anywhere" link builder.
 *
 * The problem this solves: an obsolete flyback is often unfindable under the
 * code printed on it, but very findable under one of its equivalents, on a
 * marketplace in another country, or under the local-language word for the
 * component. HR-Diemen's own site is dead, so its catalogue pages now only
 * exist in the Internet Archive.
 *
 * So rather than one search box, this builds a spread of pre-composed searches:
 * every known code OR'd together, aimed at several engines, several
 * marketplaces, and the component's name in the languages where CRT repair
 * material actually lives (German, Spanish, Portuguese, Russian, Chinese, Polish).
 *
 * Exposed as window.Sourcing.render(res) -> HTML string.
 */
(() => {
  "use strict";

  const esc = s => (s == null ? "" : String(s)).replace(/[&<>"']/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));

  // --- vocabulary -----------------------------------------------------------
  // The words a seller or a repair forum would actually use. These are the
  // terms that surface results a bare part-number search misses entirely —
  // Russian "ТДКС" and Chinese "高压包" in particular are the standard trade
  // names and bring up stock that never appears in English-language results.
  const TERMS = {
    flyback: {
      en: ['flyback', 'LOPT', '"line output transformer"'],
      de: ['Zeilentrafo', 'Zeilenübertrager'],
      es: ['"transformador de líneas"', '"transformador AT"'],
      pt: ['"transformador de linhas"', '"transformador de alta tensão"'],
      fr: ['"transformateur THT"', '"transformateur ligne"'],
      // EAT (Extra Alta Tensione) is the standard Italian trade abbreviation and
      // is usually written loose — "trasformatore di riga (EAT)", "E.A.T." — so
      // the bare acronym catches what the quoted phrase misses. Safe unquoted
      // because the part codes constrain the query.
      it: ['"trasformatore di riga"', '"trasformatore EAT"', 'EAT', '"extra alta tensione"'],
      ru: ['ТДКС', '"строчный трансформатор"'],
      pl: ['"trafopowielacz"', '"transformator linii"'],
      zh: ['行输出变压器', '高压包'],
    },
    tripler: {
      en: ['tripler', '"voltage multiplier"', 'multiplier'],
      de: ['Kaskade', 'Hochspannungskaskade'],
      es: ['triplicador', '"multiplicador de alta tensión"'],
      pt: ['triplicador', '"multiplicador de tensão"'],
      fr: ['tripleur', '"multiplicateur de tension"'],
      it: ['triplicatore', '"triplicatore di tensione"', '"moltiplicatore di tensione"'],
      ru: ['умножитель', '"умножитель напряжения"'],
      pl: ['powielacz', '"powielacz napięcia"'],
      zh: ['倍压器', '高压包'],
    },
  };

  const LANG_LABEL = {
    de: 'German', es: 'Spanish', pt: 'Portuguese', fr: 'French',
    it: 'Italian', ru: 'Russian', pl: 'Polish', zh: 'Chinese',
  };

  // --- code handling --------------------------------------------------------

  /** HR 48663 -> ["HR48663", "HR 48663", "HR-48663"]. Search engines tokenise
   *  these differently and HR codes are notorious for only matching one form. */
  function hrVariants(code) {
    const bare = code.replace(/\s+/g, '');
    const out = new Set([bare, code]);
    const m = bare.match(/^(HRT|HRD|HR)(\d+)(.*)$/i);
    if (m) {
      out.add(`${m[1]}-${m[2]}${m[3]}`);
      out.add(`${m[1]} ${m[2]}${m[3] ? ' ' + m[3] : ''}`.trim());
    }
    return [...out].filter(Boolean);
  }

  /** Rank OEM codes by how likely they are to give a useful search hit.
   *  A code that is all digits ("030715192") drowns in unrelated results;
   *  a mixed alphanumeric with punctuation ("BSC24-01N40G1") is nearly unique. */
  function scoreOem(c) {
    let s = 0;
    if (/[A-Z]/i.test(c)) s += 3;
    if (/\d/.test(c)) s += 1;
    if (/[-/.]/.test(c)) s += 1;
    if (c.replace(/[^A-Z0-9]/gi, '').length >= 7) s += 1;
    if (/^\d+$/.test(c)) s -= 3;        // pure numeric: very noisy
    if (c.length < 5) s -= 2;
    return s;
  }

  /** The codes worth putting in a combined query: the HR code plus the most
   *  distinctive equivalents. Capped — a 40-term OR query returns nothing. */
  function bestCodes(res, limit = 6) {
    const equivs = (res.equivs || []).map(e => (typeof e === 'string' ? e : e.oem));
    const ranked = [...new Set(equivs)].sort((a, b) => scoreOem(b) - scoreOem(a));
    return { hr: res.code, oems: ranked.slice(0, limit), allOems: ranked };
  }

  const quoted = c => `"${c}"`;
  const orQuery = list => list.map(quoted).join(' OR ');

  // --- link builders --------------------------------------------------------

  const ENGINES = [
    { id: 'google', label: 'Google', url: q => `https://www.google.com/search?q=${encodeURIComponent(q)}` },
    { id: 'bing', label: 'Bing', url: q => `https://www.bing.com/search?q=${encodeURIComponent(q)}` },
    { id: 'ddg', label: 'DuckDuckGo', url: q => `https://duckduckgo.com/?q=${encodeURIComponent(q)}` },
    { id: 'yandex', label: 'Yandex', url: q => `https://yandex.com/search/?text=${encodeURIComponent(q)}` },
    { id: 'baidu', label: 'Baidu', url: q => `https://www.baidu.com/s?wd=${encodeURIComponent(q)}` },
  ];

  const MARKETS = [
    { label: 'eBay UK', url: q => `https://www.ebay.co.uk/sch/i.html?_nkw=${encodeURIComponent(q)}` },
    { label: 'eBay US', url: q => `https://www.ebay.com/sch/i.html?_nkw=${encodeURIComponent(q)}` },
    { label: 'eBay DE', url: q => `https://www.ebay.de/sch/i.html?_nkw=${encodeURIComponent(q)}` },
    { label: 'AliExpress', url: q => `https://www.aliexpress.com/wholesale?SearchText=${encodeURIComponent(q)}` },
    { label: 'Taobao', url: q => `https://s.taobao.com/search?q=${encodeURIComponent(q)}` },
    { label: 'Google Shopping', url: q => `https://www.google.com/search?tbm=shop&q=${encodeURIComponent(q)}` },
  ];

  /** Communities where CRT repair knowledge actually accumulated. Searched via
   *  Google site: rather than each forum's own (mostly login-walled) search. */
  const COMMUNITIES = [
    { label: 'elektroda.pl', q: c => `site:elektroda.pl ${c}` },
    { label: 'radiokot / RU forums', q: c => `site:radiokot.ru OR site:monitor.espec.ws ${c}` },
    { label: 'eserviceinfo', q: c => `site:eserviceinfo.com ${c}` },
    { label: 'vintage-radio', q: c => `site:vintage-radio.net OR site:videokarma.org ${c}` },
    { label: 'badcaps', q: c => `site:badcaps.net ${c}` },
  ];

  /* Service-manual hunting for parts we hold no data on.
   *
   * A set's service manual contains the LOPT pin-out and its rail voltages, so
   * for the ~995 HR codes with a usage list but no technical data, the sets are
   * a way back in. The trick is that manuals are archived by CHASSIS, not by
   * model — Philips 22 K 201 / 26 C 465 / 26 K 209 are all chassis K9 — so the
   * productive search is model→chassis first, then chassis→manual. Otherwise
   * the ~8,900 models implicated look like an impossible amount of work rather
   * than a few hundred chassis. */
  const MANUAL_SITES = [
    { label: "Elektrotanya", q: m => `site:elektrotanya.com ${m}` },
    { label: "eserviceinfo", q: m => `site:eserviceinfo.com ${m}` },
    { label: "ManualsLib", q: m => `site:manualslib.com ${m} service` },
    { label: "radiomuseum", q: m => `site:radiomuseum.org ${m}` },
  ];

  function renderManuals(res) {
    const uses = res.uses || [];
    if (!uses.length) return "";
    const seen = new Set();
    const models = [];
    for (const u of uses) {
      const s = `${u.fabname || ""} ${u.model || ""}`.trim();
      if (s && !seen.has(s)) { seen.add(s); models.push(s); }
      if (models.length >= 5) break;
    }
    if (!models.length) return "";

    const g = q => ENGINES[0].url(q);
    const chassis = models.map(m =>
      chip(g(`"${m}" chassis`), m, `Find the chassis type for ${m} — manuals are archived by chassis`)).join("");
    const manuals = MANUAL_SITES.map(s =>
      chip(g(s.q(`"${models[0]}"`)), s.label, `${s.label}: ${models[0]}`)).join("");
    const broad = chip(g(`(${models.slice(0, 3).map(m => `"${m}"`).join(" OR ")}) `
      + `("service manual" OR schematic OR schaltplan OR esquema)`), "any manual",
      "All engines, several models at once, in several languages");

    return `
      <div class="src-group">
        <div class="src-label">Step 1 · find the chassis <small>— manuals are filed by chassis, not model</small></div>
        <div class="src-links">${chassis}</div>
      </div>
      <div class="src-group">
        <div class="src-label">Step 2 · service manual archives</div>
        <div class="src-links">${manuals}${broad}</div>
      </div>`;
  }

  function isTripler(res) {
    const tester_type = (res.row && res.row.tester_type) || '';
    return String(tester_type).toUpperCase() === 'TR' || /^HRT/i.test(res.code);
  }

  function chip(href, label, title) {
    return `<a class="src-link" href="${esc(href)}" target="_blank" rel="noopener noreferrer"`
      + (title ? ` title="${esc(title)}"` : '') + `>${esc(label)}</a>`;
  }

  // --- main render ----------------------------------------------------------

  function render(res, expanded) {
    const { hr, oems, allOems } = bestCodes(res);
    const variants = hrVariants(hr);
    const kind = isTripler(res) ? 'tripler' : 'flyback';
    const vocab = TERMS[kind];

    // The headline query: every distinctive code for this part, OR'd. This is
    // the one that finds stock listed under an equivalent you'd never think of.
    const comboList = [...variants, ...oems];
    const combo = orQuery(comboList);
    const hrOnly = orQuery(variants);

    const engineRow = ENGINES.map(e =>
      chip(e.url(combo), e.label, `${e.label}: all ${comboList.length} code variants at once`)).join('');

    const hrRow = ENGINES.slice(0, 4).map(e =>
      chip(e.url(hrOnly), e.label, `${e.label}: the HR code alone`)).join('');

    const marketRow = MARKETS.map(m =>
      chip(m.url(oems.length ? [hr.replace(/\s+/g, ''), ...oems.slice(0, 3)].join(' OR ') : hr), m.label,
        `Search ${m.label} for this part and its main equivalents`)).join('');

    // One Google search per language, pairing the codes with that language's
    // trade name for the component.
    const langRow = Object.keys(LANG_LABEL).map(lang => {
      const q = `(${orQuery([...variants, ...oems.slice(0, 3)])}) (${vocab[lang].join(' OR ')})`;
      return chip(ENGINES[0].url(q), LANG_LABEL[lang],
        `${LANG_LABEL[lang]}: ${vocab[lang].join(', ')}`);
    }).join('');

    const commRow = COMMUNITIES.map(c =>
      chip(ENGINES[0].url(c.q(orQuery([...variants, ...oems.slice(0, 2)]))), c.label,
        `Search ${c.label} via Google`)).join('');

    // HR-Diemen is dead; its catalogue page for this code survives only in the
    // Wayback Machine. The bare number is the path segment their site used.
    const bare = hr.replace(/^HR[TD]?\s*/i, '').replace(/\s+/g, '');
    const archiveRow = [
      chip(`https://web.archive.org/web/2015*/hrdiemen.com/reparation/flyback/model/${encodeURIComponent(bare)}`,
        'Wayback: HR-Diemen page', 'Archived hrdiemen.com catalogue page for this code'),
      chip(`https://web.archive.org/web/*/*${encodeURIComponent(bare)}*`,
        'Wayback: any URL', 'Any archived URL containing this number'),
      chip(`https://cachedview.nl/`, 'cachedview', 'Manual cache lookup'),
    ].join('');

    // Only offered where we actually lack data — for a documented part the
    // service manual is a curiosity rather than a lead.
    const hasData = !!(res.row && (res.row.mat_kv != null || res.row.tester_type))
                    || !!res.sch || (res.images || []).length > 0;
    const manualsBlock = hasData ? '' : renderManuals(res);

    const allCodes = [hr, ...allOems].join('  ');

    return `
      <details class="sourcing"${expanded ? ' open' : ''}>
        <summary>Buy or find this part &mdash; searches every one of its ${allOems.length + 1} codes
          <small>including in other languages</small></summary>
        <div class="src-body">
          <div class="src-group">
            <div class="src-label">All codes at once <small>— the widest net</small></div>
            <div class="src-links">${engineRow}</div>
          </div>
          <div class="src-group">
            <div class="src-label">HR code only</div>
            <div class="src-links">${hrRow}</div>
          </div>
          <div class="src-group">
            <div class="src-label">Buy</div>
            <div class="src-links">${marketRow}</div>
          </div>
          <div class="src-group">
            <div class="src-label">Other languages <small>— ${kind === 'tripler' ? 'Kaskade / triplicador / умножитель' : 'Zeilentrafo / ТДКС / 高压包'}</small></div>
            <div class="src-links">${langRow}</div>
          </div>
          <div class="src-group">
            <div class="src-label">Repair communities</div>
            <div class="src-links">${commRow}</div>
          </div>
          <div class="src-group">
            <div class="src-label">Archives <small>— hrdiemen.com is offline</small></div>
            <div class="src-links">${archiveRow}</div>
          </div>
          ${manualsBlock}
          <div class="src-group">
            <div class="src-label">Every known code for this part</div>
            <textarea class="src-codes" readonly rows="2">${esc(allCodes)}</textarea>
          </div>
        </div>
      </details>`;
  }

  window.Sourcing = { render, hrVariants, bestCodes };
})();
