import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import type { ViteDevServer } from 'vite'

// Custom Vite plugin to handle SMS proxy
const smsProxyPlugin = () => ({
  name: 'sms-proxy',
  configureServer(server: ViteDevServer) {
    server.middlewares.use('/api/sms', async (req, res) => {
      if (req.method === 'POST') {
        let body = '';
        req.on('data', chunk => {
          body += chunk.toString();
        });

        req.on('end', async () => {
          try {
            const { number, message, sendername } = JSON.parse(body);

            // Rebuild the body with the customData wrapper as JSON
            const payload = {
              customData: {
                number: number || '',
                message: message || '',
                sendername: sendername || 'NOLACRM',
              }
            };

            const response = await fetch('https://smspro-api.nolacrm.io/webhook/send_sms', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6',
              },
              body: JSON.stringify(payload),
            });

            const data = await response.json();
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify(data));
          } catch (error) {
            console.error('Vite Proxy Error:', error);
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify({ status: 'error', message: 'Failed to send SMS via proxy' }));
          }
        });
      }
    });

    server.middlewares.use('/api/messages', async (req, res) => {
      try {
        // Forward query params to the real backend
        const url = new URL(req.url || '', 'http://localhost');
        const queryString = url.search || '';
        
        let cloudRunUrl = `https://smspro-api.nolacrm.io/api/messages${queryString}`;
        
        // If PUT or DELETE, potentially route to /api/conversations
        if (req.method === 'PUT') {
          cloudRunUrl = `https://smspro-api.nolacrm.io/api/conversations`;
        } else if (req.method === 'DELETE') {
          const convId = url.searchParams.get('conversation_id');
          cloudRunUrl = `https://smspro-api.nolacrm.io/api/conversations?id=${convId}`;
        }

        console.log(`Dev proxy ${req.method}:`, cloudRunUrl);

        let body = '';
        if (req.method === 'POST' || req.method === 'PUT') {
          await new Promise((resolve) => {
            req.on('data', chunk => body += chunk.toString());
            req.on('end', resolve);
          });
        }

        const response = await fetch(cloudRunUrl, {
          method: req.method,
          headers: {
            'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6',
            'Content-Type': 'application/json',
          },
          body: (req.method === 'POST' || req.method === 'PUT') ? body : undefined,
        });

        const data = await response.json();
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(data));
      } catch (error) {
        console.error(`Dev proxy error for /api/messages [${req.method}]:`, error);
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ status: 'error', message: 'Failed to proxy request to backend' }));
      }
    });

    server.middlewares.use('/api/credits', async (_req, res) => {
      try {
        const cloudRunUrl = 'https://smspro-api.nolacrm.io/api/credits';
        const response = await fetch(cloudRunUrl, {
          method: 'GET',
          headers: {
            'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6',
            'Content-Type': 'application/json',
          },
        });

        const data = await response.json();
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(data));
      } catch (error) {
        console.error('Dev proxy error for /api/credits:', error);
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ status: 'error', message: 'Failed to fetch credits from backend' }));
      }
    });

    server.middlewares.use('/api/contacts', async (req, res) => {
      try {
        const url = new URL(req.url || '', 'http://localhost');
        const queryString = url.search || '';
        const cloudRunUrl = `https://smspro-api.nolacrm.io/api/ghl-contacts${queryString}`;

        console.log(`Dev proxy contacts ${req.method}:`, cloudRunUrl);

        let body = '';
        if (req.method === 'POST' || req.method === 'PUT') {
          await new Promise((resolve) => {
            req.on('data', chunk => body += chunk.toString());
            req.on('end', resolve);
          });
        }

        const response = await fetch(cloudRunUrl, {
          method: req.method,
          headers: {
            'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6',
            'Content-Type': 'application/json',
            'X-GHL-Location-ID': req.headers['x-ghl-location-id'] as string || '',
          },
          body: (req.method === 'POST' || req.method === 'PUT') ? body : undefined,
        });

        const data = await response.json();
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(data));
      } catch (error) {
        console.error('Dev proxy error for /api/contacts:', error);
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ status: 'error', message: 'Failed to proxy contact request' }));
      }
    });

    // ── Shared Login: Whitelabel branding endpoint ──
    server.middlewares.use('/api/public/whitelabel', async (req, res) => {
      try {
        const url = new URL(req.url || '', 'http://localhost');
        const queryString = url.search || '';
        const cloudRunUrl = `https://smspro-api.nolacrm.io/api/public/whitelabel${queryString}`;
        const response = await fetch(cloudRunUrl, {
          method: 'GET',
          headers: { 'Content-Type': 'application/json' },
        });
        const data = await response.json();
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(data));
      } catch (error) {
        console.error('Dev proxy error for /api/public/whitelabel:', error);
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ status: 'success', branding: { company_name: 'NOLA SMS Pro', logo_url: '', primary_color: '#2b83fa', agency_id: null } }));
      }
    });

    // ── Shared Login: Auth login endpoint ──
    server.middlewares.use('/api/auth/login', async (req, res) => {
      try {
        let body = '';
        await new Promise((resolve) => {
          req.on('data', chunk => body += chunk.toString());
          req.on('end', resolve);
        });
        const cloudRunUrl = 'https://smspro-api.nolacrm.io/api/auth/login';
        const response = await fetch(cloudRunUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body,
        });
        const data = await response.json();
        res.setHeader('Content-Type', 'application/json');
        res.statusCode = response.status;
        res.end(JSON.stringify(data));
      } catch (error) {
        console.error('Dev proxy error for /api/auth/login:', error);
        res.setHeader('Content-Type', 'application/json');
        res.statusCode = 500;
        res.end(JSON.stringify({ status: 'error', message: 'Proxy error' }));
      }
    });
  },
});

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), smsProxyPlugin()],
})
