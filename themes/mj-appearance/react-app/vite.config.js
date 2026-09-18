import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

// El build va a mj-appearance/dist. Las URLs de los assets las genera PHP
// (get_template_directory_uri) — base relativa para no acoplar el bundle
// al nombre de carpeta del tema.
export default defineConfig(({ command }) => ({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  base: command === 'build' ? './' : '/',
  server: {
    port: 5199,
  },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        // Todo con hash, también la entrada y el CSS. Antes app.js tenía nombre
        // fijo y se versionaba con ?ver=filemtime, pero una caché intermedia que
        // ignore la query (proxy del hosting, CDN) puede seguir sirviendo el
        // app.js viejo con los chunks nuevos, y la tienda revienta con
        // "does not provide an export named …". Pasó el 18/9/2026. Con el hash
        // en el nombre no hay archivo viejo que servir. PHP encuentra el archivo
        // por el patrón app-*.js (functions.php → mj_dist_asset).
        entryFileNames: 'assets/app-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/app-[hash][extname]',
      },
    },
  },
}));
