#!/usr/bin/env node
/**
 * Booked wizard build — bundles the headless core (and later the renderer)
 * with esbuild. No framework, no runtime deps: the bundle should contain only
 * our own source.
 *
 * Outputs (dist/):
 *   slots-wizard-core.esm.js   — ES module, unminified (import / tests / tooling)
 *   slots-wizard-core.cjs      — CommonJS, unminified (node require). Must use the
 *                                 .cjs extension: package.json has "type":"module",
 *                                 so a .js file would be parsed as ESM instead.
 *   slots-wizard-core.umd.js   — IIFE global `SlotsWizard`, minified (browser <script>)
 *
 * Flags: --watch (rebuild on change), --size (print gzipped size of the shipped bundle).
 */
import { build, context } from 'esbuild';
import { gzipSync } from 'node:zlib';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outdir = resolve(root, 'dist');
mkdirSync(outdir, { recursive: true });

const CORE_ENTRY = resolve(root, 'src/web/js/core/index.js');
const UI_ENTRY = resolve(root, 'src/web/js/ui/index.js');
const BUDGET_KB = 25; // core + UI gzipped — measured against the shipped UI bundle

/** Shared esbuild options for one output. */
const variant = (entry, format, outfile, minify, globalName) => ({
  entryPoints: [entry],
  outfile: resolve(outdir, outfile),
  bundle: true,
  format,
  target: ['es2020'],
  minify,
  sourcemap: minify ? true : false,
  legalComments: 'none',
  ...(globalName ? { globalName } : {}),
});

const VARIANTS = [
  // Headless core (bring-your-own-frontend).
  variant(CORE_ENTRY, 'esm', 'slots-wizard-core.esm.js', false),
  variant(CORE_ENTRY, 'cjs', 'slots-wizard-core.cjs', false),
  variant(CORE_ENTRY, 'iife', 'slots-wizard-core.umd.js', true, 'SlotsWizard'),
  // Default rendered bundle (core + vanilla renderer) — what the Twig include loads.
  variant(UI_ENTRY, 'esm', 'slots-wizard.esm.js', false),
  variant(UI_ENTRY, 'iife', 'slots-wizard.umd.js', true, 'SlotsWizard'),
  // Same rendered bundle emitted into the plugin's web assets, so the Craft
  // asset bundle can publish it (sourcePath is @anvildev/slots/web).
  variant(UI_ENTRY, 'iife', resolve(root, 'src/web/js/slots-wizard.umd.js'), true, 'SlotsWizard'),
];

const watch = process.argv.includes('--watch');
const size = process.argv.includes('--size');

function gzKb(file) {
  return gzipSync(readFileSync(resolve(outdir, file))).length / 1024;
}

function reportSize() {
  const coreKb = gzKb('slots-wizard-core.umd.js');
  const shippedKb = gzKb('slots-wizard.umd.js'); // core + renderer — the budgeted bundle
  const pct = ((shippedKb / BUDGET_KB) * 100).toFixed(0);
  const status = shippedKb <= BUDGET_KB ? 'OK' : 'OVER BUDGET';
  console.log(`core (headless, gzipped):   ${coreKb.toFixed(2)} KB`);
  console.log(`shipped core+UI (gzipped):  ${shippedKb.toFixed(2)} KB  —  ${pct}% of ${BUDGET_KB} KB budget  [${status}]`);
  if (shippedKb > BUDGET_KB) process.exitCode = 1;
}

if (watch) {
  const ctxs = await Promise.all(VARIANTS.map((v) => context(v)));
  await Promise.all(ctxs.map((c) => c.watch()));
  console.log('watching src/web/js/core for changes…');
} else {
  await Promise.all(VARIANTS.map((v) => build(v)));
  console.log('built dist/slots-wizard-core.{esm,cjs,umd} + slots-wizard.{esm,umd}');
  if (size) reportSize();
}
