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
            const { number, message, sendername } = JSON.parse(body);
            const payload = { customData: { number: number || '', message: message || '', sendername: sendername || 'NOLASMSPro' } };
            const response = await fetch('https://smspro-api.nolacrm.io/webhook/send_sms', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6' },
              body: JSON.stringify(payload)
            });
            const data = await response.json();
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify(data));
          } catch (e) {
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify({ status: 'error' }));
          }
        });
      }
    });

    server.middlewares.use('/api', async (req, res, next) => {
      if (req.url === '/sms') return next();
      const url = new URL(req.url || '', `http://${req.headers.host}`);
      let path = url.pathname;
      const method = req.method || 'GET';
      try {
        let cloudRunUrl = `https://smspro-api.nolacrm.io/api${path}${url.search}`;
        if (path === '/contacts') { cloudRunUrl = `https://smspro-api.nolacrm.io/api/ghl-contacts${url.search}`; }
        else if (path === '/messages' && (method === 'PUT' || method === 'DELETE')) { cloudRunUrl = `https://smspro-api.nolacrm.io/api/conversations${url.search}`; }
        else if (path === '/public/whitelabel') { cloudRunUrl = `https://smspro-api.nolacrm.io/api/whitelabel.php${url.search}`; }
        else if (path === '/auth/login') { cloudRunUrl = `https://smspro-api.nolacrm.io/api/login.php${url.search}`; }

        const response = await fetch(cloudRunUrl, {
          method,
          headers: {
            'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6',
            'Content-Type': 'application/json',
            ...(req.headers['x-ghl-location-id'] ? { 'X-GHL-Location-ID': req.headers['x-ghl-location-id'] as string } : {}),
            ...(req.headers['authorization'] ? { 'Authorization': req.headers['authorization'] as string } : {})
          },
          body: (method !== 'GET' && method !== 'HEAD') ? req : undefined,
          // @ts-ignore
          duplex: 'half'
        });
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
          const data = await response.json();
          res.setHeader('Content-Type', 'application/json');
          res.end(JSON.stringify(data));
        } else {
          res.statusCode = response.status;
          res.end(await response.text());
        }
      } catch (e) { next(); }
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
    emptyOutDir: true
  }
})
