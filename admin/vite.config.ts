import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5175,
    host: true,
    proxy: {
      '/api': {
        target: 'https://smspro-api.nolacrm.io',
        changeOrigin: true,
        secure: false,
      },
      '/webhook': {
        target: 'https://smspro-api.nolacrm.io',
        changeOrigin: true,
        secure: false,
      }
    }
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true
  }
})
