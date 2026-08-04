import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import type { ViteDevServer } from 'vite'

const smsProxyPlugin = () => ({
  name: 'sms-proxy',
  configureServer(server: ViteDevServer) {
    server.middlewares.use('/api/sms', async (req, res) => {
      //... implementation matching old vite.config.ts logic
      if (req.method === 'POST') {
        let body = '';
        req.on('data', chunk => { body += chunk.toString(); });
        req.on('end', async () => {
          try {
            const parsed = JSON.parse(body || '{}');
            const customData = parsed.customData || parsed;
            const payload = {
              customData: {
                ...customData,
                number: customData.number || parsed.number || '',
                message: customData.message || parsed.message || '',
                sendername: customData.sendername || parsed.sendername || 'NOLASMSPro'
              }
            };
            const response = await fetch('https://smspro-api.nolacrm.io/webhook/send_sms', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': process.env.VITE_WEBHOOK_SECRET || process.env.X_WEBHOOK_SECRET || 'f7RkQ2pL9zV3tX8cB1nS4yW6'
              },
              body: JSON.stringify(payload)
            });
            const data = await response.json();
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify(data));
          } catch {
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify({ status: 'error' }));
          }
        });
      }
    });

    server.middlewares.use('/api', async (req, res, next) => {
      if (req.url === '/sms') return next();
      const url = new URL(req.url || '', `http://${req.headers.host}`);
      const path = url.pathname;
      const method = req.method || 'GET';
      try {
        let cloudRunUrl = `https://smspro-api.nolacrm.io/api${path}${url.search}`;
        if (path === '/contacts') { cloudRunUrl = `https://smspro-api.nolacrm.io/api/ghl-contacts${url.search}`; }
        else if (path === '/messages' && (method === 'PUT' || method === 'DELETE')) { cloudRunUrl = `https://smspro-api.nolacrm.io/api/conversations${url.search}`; }
        else if (path === '/public/whitelabel') { cloudRunUrl = `https://smspro-api.nolacrm.io/api/whitelabel.php${url.search}`; }
        else if (path === '/auth/login') { cloudRunUrl = `https://smspro-api.nolacrm.io/api/login.php${url.search}`; }

        const requestOptions = {
          method,
          headers: {
            'X-Webhook-Secret': process.env.VITE_WEBHOOK_SECRET || process.env.X_WEBHOOK_SECRET || 'f7RkQ2pL9zV3tX8cB1nS4yW6',
            'Content-Type': 'application/json',
            ...(req.headers['x-ghl-location-id'] ? { 'X-GHL-Location-ID': req.headers['x-ghl-location-id'] as string } : {}),
            ...(req.headers['authorization'] ? { 'Authorization': req.headers['authorization'] as string } : {})
          },
          body: (method !== 'GET' && method !== 'HEAD') ? req : undefined,
          duplex: 'half'
        } as RequestInit & { duplex: 'half' };
        const response = await fetch(cloudRunUrl, requestOptions);
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
          const data = await response.json();
          res.setHeader('Content-Type', 'application/json');
          res.end(JSON.stringify(data));
        } else {
          res.statusCode = response.status;
          res.end(await response.text());
        }
      } catch { next(); }
    });
  }
});

export default defineConfig({
  plugins: [react(), smsProxyPlugin()],
  server: {
    port: 5173,
    host: true
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom', 'react-router-dom'],
          ui: ['react-icons', '@mui/material', 'motion', 'gsap']
        }
      }
    }
  }
})
