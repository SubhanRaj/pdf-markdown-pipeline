{{--
    Swaps the default ti-map-pin icon on any [data-state-icon] element for that state's real
    outline, sourced client-side from @svg-maps/india (CC-BY-4.0, cdn.jsdelivr.net, version
    pinned) — no npm/build step, no server-side SVG processing. Purely decorative: if the fetch
    fails (flaky office network) or a state has no matching path, the ti-map-pin fallback already
    in the markup is simply left alone — unlike the app's global icon font, a missing icon here
    never breaks the page.

    data-state-icon="Punjab"  -> that state's own outline
    data-state-icon="__all__" -> the full India map (all paths), used on the landing page's
                                  "Other States' Policy" card

    The map's source data predates two boundary changes RuleSet::STATES already reflects:
    Ladakh (split from Jammu and Kashmir, 2019) has no path at all — left on the fallback icon.
    "Dadra and Nagar Haveli and Daman and Diu" (merged 2020) is reconstructed by combining the
    map's two separate old-UT paths.
--}}
<script>
(function () {
    const MAP_URL = 'https://cdn.jsdelivr.net/npm/@svg-maps/india@2.0.0/india.svg';
    const MERGE_MAP = {
        'Dadra and Nagar Haveli and Daman and Diu': ['Dadra and Nagar Haveli', 'Daman and Diu'],
    };

    const targets = document.querySelectorAll('[data-state-icon]');
    if (!targets.length) return;

    fetch(MAP_URL)
        .then(res => res.ok ? res.text() : Promise.reject(res.status))
        .then(svgText => {
            const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
            const allPaths = Array.from(doc.querySelectorAll('path'));
            const byLabel = new Map(allPaths.map(p => [p.getAttribute('aria-label'), p]));

            // Detached SVG used purely to measure a path's real bounding box via getBBox() —
            // must be in the DOM (off-screen) for the browser to compute it.
            const measure = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            measure.setAttribute('style', 'position:absolute;width:0;height:0;overflow:hidden');
            document.body.appendChild(measure);

            targets.forEach(el => {
                const key = el.dataset.stateIcon;
                let paths;

                if (key === '__all__') {
                    paths = allPaths;
                } else {
                    const direct = byLabel.get(key);
                    paths = direct ? [direct] : (MERGE_MAP[key] || []).map(k => byLabel.get(k)).filter(Boolean);
                }

                if (!paths.length) return; // leave the ti-map-pin fallback as-is

                const clones = paths.map(p => p.cloneNode(true));
                clones.forEach(c => measure.appendChild(c));
                let box;
                if (key === '__all__') {
                    box = measure.getBBox(); // whole-map viewBox, not per-path
                } else {
                    // Union bbox across all cloned paths for this state/UT.
                    box = clones.reduce((acc, c) => {
                        const b = c.getBBox();
                        if (!acc) return b;
                        const x2 = Math.max(acc.x + acc.width, b.x + b.width);
                        const y2 = Math.max(acc.y + acc.height, b.y + b.height);
                        acc.x = Math.min(acc.x, b.x);
                        acc.y = Math.min(acc.y, b.y);
                        acc.width = x2 - acc.x;
                        acc.height = y2 - acc.y;
                        return acc;
                    }, null);
                }
                clones.forEach(c => measure.removeChild(c));

                const pad = Math.max(box.width, box.height) * 0.06;
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', `${box.x - pad} ${box.y - pad} ${box.width + pad * 2} ${box.height + pad * 2}`);
                svg.setAttribute('class', el.querySelector('i')?.className.replace('ti ti-map-pin', '') || '');
                svg.setAttribute('fill', 'currentColor');
                clones.forEach(c => { c.removeAttribute('id'); c.removeAttribute('aria-label'); svg.appendChild(c); });

                el.innerHTML = '';
                el.appendChild(svg);
            });

            measure.remove();
        })
        .catch(() => { /* network hiccup — fallback icons already in the markup, nothing to do */ });
})();
</script>
