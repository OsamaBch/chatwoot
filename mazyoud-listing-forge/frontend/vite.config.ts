import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Desktop dev: Vite serves the UI on 5173 and proxies API + working files to
// the Express backend on 5174, so the browser only ever talks to one origin.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: false,
    proxy: {
      '/api': { target: 'http://localhost:5174', changeOrigin: true },
      '/files': { target: 'http://localhost:5174', changeOrigin: true },
    },
  },
});
