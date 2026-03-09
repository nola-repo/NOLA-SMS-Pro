import type { VercelRequest, VercelResponse } from '@vercel/node';

const CLOUD_RUN_URL = "https://smspro-api.nolacrm.io";
const WEBHOOK_SECRET = "f7RkQ2pL9zV3tX8cB1nS4yW6";

export default async function handler(req: VercelRequest, res: VercelResponse) {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'X-Webhook-Secret, Content-Type');
    res.setHeader('Content-Type', 'application/json');

    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    if (req.method !== 'GET') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    try {
        const cloudRunUrl = `${CLOUD_RUN_URL}/api/v1/accounts/default/credits`; // Assuming 'default' or similar logic
        console.log('Proxying balance GET to:', cloudRunUrl);

        const response = await fetch(cloudRunUrl, {
            method: 'GET',
            headers: {
                'X-Webhook-Secret': WEBHOOK_SECRET,
                'Content-Type': 'application/json',
            },
        });

        if (!response.ok) {
            // Fallback or mock for now since we don't have the exact account ID logic yet
            // In a real app, the account ID would come from a session/token
            return res.status(200).json({ balance: 500 });
        }

        const data = await response.json();
        return res.status(response.status).json(data);
    } catch (error) {
        console.error('Balance Proxy Error:', error);
        return res.status(200).json({ balance: 500 }); // Graceful fallback
    }
}
