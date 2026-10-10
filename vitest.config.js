import { defineConfig } from 'vitest/config';

// The headless core is DOM-free by contract, so its tests run in the plain
// Node environment; the renderer needs a DOM. `environmentMatchGlobs` (the
// old way to split that by glob) was removed in Vitest 5 — `test.projects`
// is the replacement, one sub-project per environment.
export default defineConfig({
  // Inline empty PostCSS config so Vite doesn't walk up and load the host
  // project's postcss.config.js (which pulls in autoprefixer we don't have).
  css: { postcss: {} },
  test: {
    coverage: {
      include: ['src/web/js/core/**/*.js', 'src/web/js/ui/**/*.js'],
      exclude: ['**/*.test.js'],
    },
    projects: [
      {
        test: {
          name: 'core',
          include: ['src/web/js/core/**/*.test.js'],
          environment: 'node',
          globals: false,
        },
      },
      {
        test: {
          name: 'ui',
          include: ['src/web/js/ui/**/*.test.js'],
          environment: 'jsdom',
          globals: false,
        },
      },
    ],
  },
});
