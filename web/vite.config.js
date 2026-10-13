import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), '');

  return {
    plugins: [react()],
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
