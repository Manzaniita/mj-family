import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

/*
 * El build va a mj-turnos-online/dist (app.js + app.css). Las URLs las arma
 * PHP (MJTO_URL) — base relativa para no acoplar el bundle a la carpeta.
 *
 * Un solo bundle sirve tres contextos: el panel (página propia), el shortcode
 * [mj_turnos] (dentro de cualquier tema, incluso MJ Appearance que ya trae su
 * propio Tailwind) y el constructor de campos (dentro de wp-admin). Para no
 * pisar ni ser pisados por el CSS del anfitrión, el plugin `mjtoScope`
 * reescribe TODOS los selectores del CSS final para que vivan bajo
 * `.mjto-root` (los contenedores donde montamos React) y `:root` pasa a ser
 * `.mjto-root`: los tokens de Tailwind quedan encerrados en nuestra raíz.
 * No usamos el preflight de Tailwind (sería global): el reset chico y
 * acotado está en src/styles/app.css.
 */
const ROOT = '.mjto-root';
const PAGE = '.mjto-page';

function mjtoScope() {
  return {
    postcssPlugin: 'mjto-scope',
    Once(css) {
      css.walkRules((rule) => {
        const parent = rule.parent;
        if (parent && parent.type === 'atrule' && /keyframes$/i.test(parent.name)) return;
        const next = [];
        for (const raw of rule.selectors) {
          const sel = raw.trim();
          if (!sel) continue;
          // Ya está acotado (lo escribimos a mano en app.css).
          if (sel.includes(ROOT) || sel.includes(PAGE)) {
            next.push(sel);
            continue;
          }
          if (sel === ':host') continue;
          if (sel === ':root') {
            next.push(ROOT);
            continue;
          }
          if (sel.startsWith(':root')) {
            next.push(ROOT + sel.slice(5));
            continue;
          }
          next.push(`${ROOT} ${sel}`);
        }
        if (next.length) rule.selectors = next;
        else rule.remove();
      });
    },
  };
}
mjtoScope.postcss = true;

export default defineConfig(({ command }) => ({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  base: command === 'build' ? './' : '/',
  css: {
    postcss: {
      plugins: [mjtoScope()],
    },
  },
  server: {
    port: 5198,
  },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    rollupOptions: {
      // Entrada JS directa: index.html es sólo para `npm run dev`, no va a dist/.
      input: path.resolve(__dirname, 'src/main.jsx'),
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app-[name].js',
        assetFileNames: 'app[extname]',
        inlineDynamicImports: true,
      },
    },
  },
}));
