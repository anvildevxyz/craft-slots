import { defineConfig } from 'vitest/config';

// The headless core is DOM-free by contract, so tests run in the plain
// Node environment. The M2 renderer will add a jsdom project of its own.
export default defineConfig({
  // Inline empty PostCSS config so Vite doesn't walk up and load the host
  // project's postcss.config.js (which pulls in autoprefixer we don't have).
  css: { postcss: {} },
  test: {
    include: ['src/web/js/core/**/*.test.js', 'src/web/js/ui/**/*.test.js'],
    // Core is DOM-free (node); the renderer needs a DOM (jsdom).
    environment: 'node',
    environmentMatchGlobs: [['src/web/js/ui/**', 'jsdom']],
    globals: false,
    coverage: {
      include: ['src/web/js/core/**/*.js', 'src/web/js/ui/**/*.js'],
      exclude: ['**/*.test.js'],
    },
  },
});
