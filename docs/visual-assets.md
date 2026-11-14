<p><a href="../README.md">WP ClamAV</a> / <a href="README.md">Documentation</a></p>

# Visual assets and licenses

The README and guides use local, editable SVG artwork. No icon CDN, web font,
analytics script or paid image library is required. The only live badge is served
by this repository's GitHub Actions workflow.

## Sources and permitted use

| Asset | Source | License and changes |
| --- | --- | --- |
| [Header illustration](assets/hero.svg), [mobile layout](assets/hero-mobile.svg) | Original WP ClamAV compositions; shield geometry from the Lucide icon below. | Original work: [GPL-2.0-or-later](../LICENSE). Lucide portion retains its upstream ISC notice. |
| [Upload flow diagram](assets/upload-flow.svg), [mobile layout](assets/upload-flow-mobile.svg) | Original project artwork, describing the automatic decision path. | [GPL-2.0-or-later](../LICENSE). Editable text and geometric shapes; no external resources. |
| [PHP](assets/badges/php.svg), [license](assets/badges/license.svg), [status](assets/badges/status.svg) badges | Original project SVGs. | [GPL-2.0-or-later](../LICENSE). Static values; update only when source facts change. |
| [Local icons](assets/icons/) | [Lucide](https://lucide.dev), pinned to [`55782beff3a0133df79c417eb68f96e8f19e2404`](https://github.com/lucide-icons/lucide/tree/55782beff3a0133df79c417eb68f96e8f19e2404/icons). | Upstream ISC, with the MIT notice for applicable Feather-derived icons. The local SVG stroke changes from `currentColor` to `#0f766e` so standalone images render consistently. |
| Live CI badge | [GitHub Actions badge endpoint](https://github.com/Barbarrosa/wp-clamav/actions/workflows/compact-plugin.yml/badge.svg?branch=main). | Remotely generated GitHub status image; not vendored project artwork. Links to the actual workflow. |

The exact upstream icon paths are `icons/shield-check.svg`, `icons/book-open.svg`,
`icons/code-xml.svg`, `icons/settings-2.svg`, `icons/server.svg` and
`icons/scan-line.svg`. The pinned upstream notice explicitly includes `server`
among Feather-derived icons.

Keep the complete [Lucide / Feather license notice](assets/licenses/lucide.txt)
with distributions. [The source manifest](assets/sources.json) records original
URLs, hashes and adaptations for the local files. It is an attribution/integrity
record, not a release signature.

## Design and accessibility

- A dark ink header, teal accents and warm hold states provide a small consistent
  palette across GitHub's light and dark page backgrounds.
- Navigation and critical behavior remain real Markdown text. Diagrams have alt
  text and nearby prose; decorative icons use empty alt text beside visible labels.
- Responsive `picture` elements select simpler header and vertical workflow art
  on narrow screens. The default images remain available if a renderer lacks that support.
- SVGs contain no scripts, remote images, embedded fonts or `foreignObject`.
  Font families are system-font references; no font files are distributed.
- Relative asset links work in a checkout and in the packaged documentation.
  SVG source is the editable artwork; no design-tool account or build is needed.
- Integration names identify compatible components. No upstream WordPress,
  Forminator or ClamAV logos or endorsement/certification badges are used.

## Keep the badge claims honest

The PHP badge is the declared runtime minimum. The experimental badge describes
project status, and the license badge links to the source license. The live CI
badge reports a workflow result, not deployment qualification, malware absence or
a measured test-coverage percentage.

Native build compatibility, tested WordPress/Forminator versions and remaining
hosting qualification appear in the README's compatibility table instead of
ambiguous certification badges.

Next: [Development conventions](development.md#maintain-the-documentation) ·
[Documentation index](README.md)
