// Generates the ATT MCP Abilities brand assets from code (no design tool needed).
//
//   cd brand && npm install && npm run build          writes into the repo (see TARGETS)
//   npm run preview                                    also writes preview sheets to brand/out/
//
// Design: an "A" drawn as a small network — agents connected to the site's abilities — with an
// amber spark at the crossbar, on a blue tile. The admin-menu icon is a monochrome, spark-less
// version made of filled shapes only, because WordPress recolours an SVG menu icon's `fill`
// values to match the admin colour scheme. Banner text is Inter (SIL OFL 1.1) converted to
// outlines, so the SVG renders identically everywhere.
import { Resvg } from '@resvg/resvg-js';
import opentype from 'opentype.js';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const TARGETS = {
	logo: path.join(ROOT, 'att-mcp-abilities/assets/logo.svg'),
	menu: path.join(ROOT, 'att-mcp-abilities/assets/menu-icon.svg'),
	wporg: path.join(ROOT, '.wordpress-org'),
};
const previewArg = process.argv.indexOf('--preview');
const PREVIEW = previewArg > -1 ? path.resolve(process.cwd(), process.argv[previewArg + 1] || 'out') : null;

const r1 = (n) => Math.round(n * 10) / 10;
const loadFont = (weight) => {
	const buf = fs.readFileSync(path.join(HERE, `node_modules/@fontsource/inter/files/inter-latin-${weight}-normal.woff`));
	return opentype.parse(buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength));
};

// ---- Palette -----------------------------------------------------------------
const C = {
	bgA: '#3D7BF7', // tile gradient start (top-left)
	bgB: '#1C2F7A', // tile gradient end (bottom-right)
	spark: '#FFC53D',
	glow: '#FFD66B',
	night1: '#0B1A45', // banner background
	night2: '#1B3A94',
	tagline: '#C9D6FF',
};

// ---- Glyph geometry (256 × 256 design grid) -----------------------------------
const G = { apex: [128, 58], bl: [58, 196], br: [198, 196], barY: 141 };
const legX = (g, y, foot) => g.apex[0] + (foot[0] - g.apex[0]) * ((y - g.apex[1]) / (foot[1] - g.apex[1]));

function sparkle(cx, cy, R, k = 0.2) {
	const q = R * k;
	return `M${r1(cx)} ${r1(cy - R)}Q${r1(cx + q)} ${r1(cy - q)} ${r1(cx + R)} ${r1(cy)}Q${r1(cx + q)} ${r1(cy + q)} ${r1(cx)} ${r1(cy + R)}Q${r1(cx - q)} ${r1(cy + q)} ${r1(cx - R)} ${r1(cy)}Q${r1(cx - q)} ${r1(cy - q)} ${r1(cx)} ${r1(cy - R)}Z`;
}

/** Full-colour glyph (white network "A" + amber spark). `p` prefixes gradient ids. */
function glyph(p) {
	const [ax, ay] = G.apex, [lx, ly] = G.bl, [rx, ry] = G.br, y = G.barY;
	return `<radialGradient id="${p}glow"><stop offset="0" stop-color="${C.glow}" stop-opacity=".55"/><stop offset="1" stop-color="${C.glow}" stop-opacity="0"/></radialGradient>` +
		`<g fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round">` +
		`<path d="M${lx} ${ly}L${ax} ${ay}L${rx} ${ry}" stroke-width="20"/>` +
		`<path d="M${r1(legX(G, y, G.bl))} ${y}H${r1(legX(G, y, G.br))}" stroke-width="14"/></g>` +
		`<g fill="#fff"><circle cx="${ax}" cy="${ay}" r="22"/><circle cx="${lx}" cy="${ly}" r="22"/><circle cx="${rx}" cy="${ry}" r="22"/></g>` +
		`<circle cx="128" cy="${y}" r="48" fill="url(#${p}glow)"/>` +
		`<path d="${sparkle(128, y, 30)}" fill="${C.spark}"/>`;
}

/** Background tile (gradient + soft top-left highlight). */
function tileBg(p, rx) {
	return `<linearGradient id="${p}bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${C.bgA}"/><stop offset="1" stop-color="${C.bgB}"/></linearGradient>` +
		`<radialGradient id="${p}hl" cx=".22" cy=".12" r=".8"><stop offset="0" stop-color="#fff" stop-opacity=".2"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>` +
		`<rect width="256" height="256" rx="${rx}" fill="url(#${p}bg)"/><rect width="256" height="256" rx="${rx}" fill="url(#${p}hl)"/>`;
}

const svgTile = (rx) =>
	`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" role="img" aria-label="ATT MCP Abilities">${tileBg('t', rx)}${glyph('t')}</svg>\n`;

/** Monochrome admin-menu glyph: filled shapes only (WordPress recolours `fill`); no spark, so the A's counter stays open at 20 px. */
function svgMenu() {
	const g = { apex: [128, 52], bl: [52, 204], br: [204, 204], barY: 143 };
	const hw = 13, nodeR = 32;
	const leg = (a, b) => {
		const dx = b[0] - a[0], dy = b[1] - a[1], L = Math.hypot(dx, dy), nx = (-dy / L) * hw, ny = (dx / L) * hw;
		return `M${r1(a[0] + nx)} ${r1(a[1] + ny)}L${r1(b[0] + nx)} ${r1(b[1] + ny)}L${r1(b[0] - nx)} ${r1(b[1] - ny)}L${r1(a[0] - nx)} ${r1(a[1] - ny)}Z`;
	};
	const x1 = legX(g, g.barY, g.bl), x2 = legX(g, g.barY, g.br);
	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">` +
		`<path fill="black" d="${leg(g.apex, g.bl)}${leg(g.apex, g.br)}"/>` +
		`<rect fill="black" x="${r1(x1)}" y="${g.barY - 11}" width="${r1(x2 - x1)}" height="22"/>` +
		`<circle fill="black" cx="${g.apex[0]}" cy="${g.apex[1]}" r="${nodeR}"/>` +
		`<circle fill="black" cx="${g.bl[0]}" cy="${g.bl[1]}" r="${nodeR}"/>` +
		`<circle fill="black" cx="${g.br[0]}" cy="${g.br[1]}" r="${nodeR}"/></svg>\n`;
}

// ---- Banner --------------------------------------------------------------------
// Manual Latin layout (glyph advance + GPOS kerning + tracking); opentype.js's shaper cannot
// apply Inter's ccmp lookups, and plain Latin text needs no shaping.
function layout(font, text, x, y, size, tracking = 0) {
	const scale = size / font.unitsPerEm;
	const glyphs = [...text].map((ch) => font.charToGlyph(ch));
	const out = new opentype.Path();
	let pen = x;
	glyphs.forEach((g, i) => {
		out.extend(g.getPath(pen, y, size));
		pen += g.advanceWidth * scale + tracking * size;
		if (i < glyphs.length - 1) pen += font.getKerningValue(g, glyphs[i + 1]) * scale;
	});
	return { d: out.toPathData(1), width: pen - x - tracking * size };
}

function textPath(font, text, x, y, size, fill, maxWidth, tracking = 0) {
	let s = size;
	while (maxWidth && layout(font, text, x, y, s, tracking).width > maxWidth) s -= 1;
	const { d, width } = layout(font, text, x, y, s, tracking);
	return { svg: `<path fill="${fill}" d="${d}"/>`, width, size: s };
}

function lcg(seed) {
	let s = seed >>> 0;
	return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296);
}

/** Faint node-and-edge pattern for the banner's right side (deterministic). */
function network(W, H, x0) {
	const rnd = lcg(7);
	const pts = [];
	for (let i = 0; i < 22; i++) {
		pts.push([x0 + rnd() * (W - x0 - 36), 30 + rnd() * (H - 60)]);
	}
	let lines = '';
	pts.forEach((p, i) => {
		pts.map((q, j) => [Math.hypot(p[0] - q[0], p[1] - q[1]), j]).filter(([, j]) => j !== i).sort((a, b) => a[0] - b[0]).slice(0, 2)
			.forEach(([, j]) => { if (j > i) lines += `M${r1(p[0])} ${r1(p[1])}L${r1(pts[j][0])} ${r1(pts[j][1])}`; });
	});
	const dots = pts.map((p, i) => `<circle cx="${r1(p[0])}" cy="${r1(p[1])}" r="${i % 7 === 0 ? 6 : 4}" fill="${i % 7 === 0 ? C.spark : '#fff'}" fill-opacity="${i % 7 === 0 ? 0.55 : 0.18}"/>`).join('');
	return `<path d="${lines}" stroke="#fff" stroke-opacity=".09" stroke-width="2" fill="none"/>${dots}`;
}

function svgBanner(W = 1544, H = 500) {
	const bold = loadFont(700), medium = loadFont(500);
	const tx = 470, maxW = W - tx - 70;
	const title = textPath(bold, 'ATT MCP Abilities', tx, 226, 88, '#fff', maxW, -0.02);
	const l1 = textPath(medium, 'Let AI agents build and edit your WordPress site,', tx, 292, 32, C.tagline, maxW);
	const l2 = textPath(medium, 'with per-ability control, undo and an audit log.', tx, 338, l1.size, C.tagline, maxW);
	const netX = Math.max(tx + title.width, tx + l1.width, tx + l2.width) + 90;
	const s = 300 / 256;
	return `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">` +
		`<defs><linearGradient id="night" x1="0" y1="0" x2="1" y2=".35"><stop offset="0" stop-color="${C.night1}"/><stop offset="1" stop-color="${C.night2}"/></linearGradient>` +
		`<radialGradient id="halo"><stop offset="0" stop-color="${C.bgA}" stop-opacity=".45"/><stop offset="1" stop-color="${C.bgA}" stop-opacity="0"/></radialGradient>` +
		`<filter id="shadow" x="-20%" y="-20%" width="140%" height="150%"><feGaussianBlur in="SourceAlpha" stdDeviation="14"/><feOffset dy="12"/><feComponentTransfer><feFuncA type="linear" slope=".45"/></feComponentTransfer><feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>` +
		`<rect width="${W}" height="${H}" fill="url(#night)"/>` +
		`<circle cx="258" cy="250" r="300" fill="url(#halo)"/>` +
		network(W, H, Math.min(netX, W - 260)) +
		`<g transform="translate(108 100) scale(${s})" filter="url(#shadow)">${tileBg('b', 56)}${glyph('b')}</g>` +
		title.svg + l1.svg + l2.svg + `</svg>\n`;
}

// ---- Output --------------------------------------------------------------------
const png = (svg, width) => new Resvg(svg, { fitTo: { mode: 'width', value: width }, background: 'rgba(0,0,0,0)' }).render().asPng();
const write = (file, data) => {
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(file, data);
	console.log('wrote', path.relative(ROOT, file));
};

const logo = svgTile(56); // rounded tile: admin header, README
const icon = svgTile(0);  // full-bleed square: WordPress.org directory
const menu = svgMenu();
const banner = svgBanner();

write(TARGETS.logo, logo);
write(TARGETS.menu, menu);
write(path.join(TARGETS.wporg, 'icon.svg'), icon);
write(path.join(TARGETS.wporg, 'icon-128x128.png'), png(icon, 128));
write(path.join(TARGETS.wporg, 'icon-256x256.png'), png(icon, 256));
write(path.join(TARGETS.wporg, 'banner-772x250.png'), png(banner, 772));
write(path.join(TARGETS.wporg, 'banner-1544x500.png'), png(banner, 1544));

if (PREVIEW) {
	const b64 = (buf) => `data:image/png;base64,${Buffer.from(buf).toString('base64')}`;
	const tint = (svg, colour) => svg.replace(/fill="black"/g, `fill="${colour}"`);
	const sheet = `<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="880" viewBox="0 0 1600 880">
<rect width="1600" height="880" fill="#f0f0f1"/>
<image href="${b64(png(logo, 256))}" x="40" y="40" width="256" height="256"/>
<image href="${b64(png(icon, 128))}" x="330" y="40" width="128" height="128"/>
<image href="${b64(png(icon, 64))}" x="490" y="40" width="64" height="64"/>
<image href="${b64(png(icon, 32))}" x="580" y="40" width="32" height="32"/>
<rect x="330" y="200" width="300" height="96" fill="#1d2327"/>
<image href="${b64(png(tint(menu, '#a7aaad'), 20))}" x="345" y="214" width="20" height="20"/>
<image href="${b64(png(tint(menu, '#72aee6'), 20))}" x="345" y="252" width="20" height="20"/>
<image href="${b64(png(tint(menu, '#a7aaad'), 20))}" x="400" y="206" width="84" height="84" image-rendering="optimizeSpeed"/>
<image href="${b64(png(tint(menu, '#a7aaad'), 40))}" x="520" y="206" width="84" height="84"/>
<image href="${b64(png(banner, 1544))}" x="40" y="340" width="1520" height="492"/>
</svg>`;
	write(path.join(PREVIEW, 'preview.png'), png(sheet, 1600));
}
