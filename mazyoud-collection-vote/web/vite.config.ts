import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// In dev, proxy API/img/health to the backend on :8080 so the browser talks to
// a single origin (no CORS). In prod the Express server serves this build.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8080',
      '/img': 'http://localhost:8080',
      '/healthz': 'http://localhost:8080',
    },
  },
  build: {
    // Emit straight into the server image's static dir.
    outDir: '../server/public',
    emptyOutDir: true,
  },
});
