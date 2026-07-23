#!/usr/bin/env node
// Diffs the routes declared in src/routes.php against the paths that
// tests/e2e/**/*.ts actually navigate to or POST/PUT/PATCH/DELETE against,
// via static regex scanning (no server, no Docker, no Playwright run needed).
//
// LIMITATION (read before trusting a "0 references" line): this only sees
// `.goto(...)` and `page.request.<method>(...)` calls in the TypeScript
// source. Routes only reached by clicking a submit button whose <form
// action="..."> lives in a Twig template (e.g. POST /{lang}/cart/add) will
// show as uncovered here even when a real browser click exercises them in a
// passing spec. Treat this as a "what's definitely NOT exercised via direct
// navigation/API call" list, not a full coverage report — cross-check
// anything it flags against the actual spec before assuming it's untested.
//
// Usage: node scripts/e2e-route-coverage.js

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');

function normalize(routePath) {
  if (routePath === '') return '/';
  let p = routePath.replace(/\{[^}]+\}/g, '*');
  if (!p.startsWith('/')) p = '/' + p;
  return p.replace(/\/+$/, '') || '/';
}

function parseRoutesPhp(file) {
  const lines = fs.readFileSync(file, 'utf8').split('\n');
  const routes = [];
  let groupPrefix = null;
  let groupDepth = 0; // brace depth relative to the group's own opening '{'

  for (const line of lines) {
    const trimmed = line.trim();

    const groupStart = trimmed.match(/\$app->group\(\s*'([^']*)'/);
    if (groupStart && groupPrefix === null) {
      groupPrefix = groupStart[1];
      groupDepth = (line.match(/\{/g) || []).length - (line.match(/\}/g) || []).length;
      continue;
    }

    if (groupPrefix !== null) {
      // Route path placeholders like '{id:[0-9]+}' are brace-balanced within
      // the same line, so they don't perturb this count — only real PHP
      // block braces (e.g. the inline /translate closure's function body)
      // change the depth across lines.
      groupDepth += (line.match(/\{/g) || []).length - (line.match(/\}/g) || []).length;
      if (groupDepth <= 0) {
        groupPrefix = null;
        continue;
      }
    }

    const target = groupPrefix !== null ? '\\$group' : '\\$app';
    const re = new RegExp(target + "->(get|post|put|patch|delete)\\(\\s*'([^']*)'");
    const m = trimmed.match(re);
    if (!m) continue;

    const [, method, routePath] = m;
    const full = groupPrefix !== null ? groupPrefix + routePath : routePath;
    routes.push({ method: method.toUpperCase(), path: normalize(full) });
  }

  return routes;
}

function walkTsFiles(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...walkTsFiles(full));
    else if (entry.name.endsWith('.ts')) out.push(full);
  }
  return out;
}

function extractStringValue(raw) {
  // Strip surrounding quotes/backticks, then collapse ${...} interpolations
  // and query strings so `/${this.lang}/shop/${sku}` and `/{lang}/shop/{slug}`
  // normalize to the same shape.
  const inner = raw.slice(1, -1);
  return inner.replace(/\$\{[^}]*\}/g, '*').split('?')[0];
}

function parseE2eCalls(files) {
  const hits = new Set();
  const STRING = "(`[^`]*`|'[^']*'|\"[^\"]*\")";

  for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');

    for (const m of src.matchAll(new RegExp('\\.goto\\(\\s*' + STRING, 'g'))) {
      const value = extractStringValue(m[1]);
      if (value.startsWith('/')) hits.add('GET ' + normalize(value));
    }

    for (const m of src.matchAll(new RegExp('page\\.request\\.(get|post|put|patch|delete)\\(\\s*' + STRING, 'g'))) {
      const value = extractStringValue(m[2]);
      if (value.startsWith('/')) hits.add(m[1].toUpperCase() + ' ' + normalize(value));
    }
  }

  return hits;
}

const routes = parseRoutesPhp(path.join(ROOT, 'src/routes.php'));
const hits = parseE2eCalls(walkTsFiles(path.join(ROOT, 'tests/e2e')));

const seen = new Set();
const covered = [];
const uncovered = [];

for (const route of routes) {
  const key = route.method + ' ' + route.path;
  if (seen.has(key)) continue;
  seen.add(key);
  (hits.has(key) ? covered : uncovered).push(key);
}

console.log(`Routes declared in src/routes.php: ${seen.size}`);
console.log(`Referenced via .goto()/page.request() in tests/e2e: ${covered.length}`);
console.log(`Not referenced that way: ${uncovered.length}\n`);

console.log('--- Not referenced via direct navigation/API call ---');
for (const key of uncovered.sort()) console.log('  ' + key);

console.log('\n--- Referenced ---');
for (const key of covered.sort()) console.log('  ' + key);

console.log(
  '\nReminder: "not referenced" includes routes only reached by clicking a\n' +
  'form submit button (action="..." lives in the Twig template, invisible to\n' +
  'this static scan) — verify against the actual spec before treating a line\n' +
  'above as a real test gap.'
);
