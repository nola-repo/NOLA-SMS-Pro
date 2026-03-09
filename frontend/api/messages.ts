import type { VercelRequest, VercelResponse } from '@vercel/node';

const CLOUD_RUN_URL = "https://smspro-api.nolacrm.io";
const WEBHOOK_SECRET = "f7RkQ2pL9zV3tX8cB1nS4yW6";

export default async function handler(req: VercelRequest, res: VercelResponse) {
  // Set CORS headers for the response
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'X-Webhook-Secret, Content-Type');
  res.setHeader('Content-Type', 'application/json');

  // Handle preflight
  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  try {
    // Route based on action parameter
    const action = req.query.action as string;

    if (action === 'fetch_bulk_messages') {
      // Fetch all bulk messages from Firestore
      const cloudRunUrl = `${CLOUD_RUN_URL}/api/bulk-campaigns`;
      console.log('Proxying fetch_bulk_messages to:', cloudRunUrl);

      const response = await fetch(cloudRunUrl, {
        method: 'GET',
        headers: {
          'X-Webhook-Secret': WEBHOOK_SECRET,
          'Content-Type': 'application/json',
        },
      });

      const data = await response.json();
      return res.status(response.status).json(data);
    }

    if (action === 'fetch_conversations') {
      // Fetch all conversations from Firestore.
      // Returns [] gracefully if the Cloud Run endpoint isn't deployed yet.
      const cloudRunUrl = `${CLOUD_RUN_URL}/api/conversations`;
      console.log('Proxying fetch_conversations to:', cloudRunUrl);

      try {
        const response = await fetch(cloudRunUrl, {
          method: 'GET',
          headers: {
            'X-Webhook-Secret': WEBHOOK_SECRET,
            'Content-Type': 'application/json',
          },
        });

        if (!response.ok) {
          // Endpoint not yet deployed — return empty array so frontend falls back silently
          return res.status(200).json([]);
        }

        const data = await response.json();
        return res.status(200).json(data);
      } catch {
        return res.status(200).json([]);
      }
    }

    // Route based on method
    if (req.method === 'GET') {
      // GET /api/messages - fetch messages, forwarding all query params as-is
      const queryParams = new URLSearchParams();
      for (const [key, value] of Object.entries(req.query)) {
        if (value && key !== 'action') {
          queryParams.append(key, Array.isArray(value) ? value[0] : value);
        }
      }

      const cloudRunUrl = `${CLOUD_RUN_URL}/api/messages?${queryParams.toString()}`;
      console.log('Proxying GET to:', cloudRunUrl);

      const response = await fetch(cloudRunUrl, {
        method: 'GET',
        headers: {
          'X-Webhook-Secret': WEBHOOK_SECRET,
          'Content-Type': 'application/json',
        },
      });

      const data = await response.json();
      console.log('Cloud Run response:', data);
      return res.status(response.status).json(data);
    } else if (req.method === 'POST') {
      // POST /api/messages - send SMS
      const cloudRunUrl = `${CLOUD_RUN_URL}/webhook/send_sms`;
      console.log('Proxying POST to:', cloudRunUrl);
      console.log('Request body:', req.body);

      const response = await fetch(cloudRunUrl, {
        method: 'POST',
        headers: {
          'X-Webhook-Secret': WEBHOOK_SECRET,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(req.body),
      });

      const data = await response.json();
      console.log('Cloud Run SMS response:', data);
      return res.status(response.status).json(data);
    } else {
      return res.status(405).json({ error: 'Method not allowed' });
    }
  } catch (error) {
    console.error('Proxy Error:', error);
    return res.status(500).json({
      success: false,
      error: 'Proxy failed',
      message: error instanceof Error ? error.message : 'Unknown error',
    });
  }
}
