import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { createReadStream, existsSync, statSync } from 'node:fs';
import path from 'node:path';

// Dev only: serves ../public/media (repo root) at /media so piece images work locally.
const localMedia = () => ({
  name: 'local-media',
  configureServer(server) {
    const root = path.resolve(server.config.root, '../public/media');
    server.middlewares.use('/media', (req, res, next) => {
      let file;
      try {
        file = path.join(root, decodeURIComponent(req.url.split('?')[0]));
      } catch {
        return next();
      }
      if (!file.startsWith(root + path.sep) || !file.endsWith('.jpg') || !existsSync(file) || !statSync(file).isFile()) {
        return next();
      }
      res.setHeader('Content-Type', 'image/jpeg');
      createReadStream(file).pipe(res);
    });
  },
});

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), '');

  return {
    plugins: [react(), localMedia()],
    base: command === 'build' ? '/app/' : '/',
    build: { outDir: '../public/app', emptyOutDir: true },
    server: {
      proxy: {
        '/api': {
          target: env.API_PROXY_TARGET || 'http://localhost:5173',
          changeOrigin: true,
        },
      },
    },
    publicDir: 'public',
  };
});