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
        entryFileNames: 'assets/app.js',
        // Con hash: al actualizar el tema, el navegador no puede reusar un chunk viejo
        // (app.js ya cambia de versión por filemtime; los chunks tienen que acompañar).
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/app[extname]',
      },
    },
  },
}));
