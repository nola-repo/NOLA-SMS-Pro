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
        const urlParams = new URL(`https://dummy.com${req.url}`).search;
        const cloudRunUrl = `${CLOUD_RUN_URL}/api/get_credit_transactions.php${urlParams}`;
        console.log('Proxying get_credit_transactions to:', cloudRunUrl);

        const response = await fetch(cloudRunUrl, {
            method: 'GET',
            headers: {
                'X-Webhook-Secret': WEBHOOK_SECRET,
                'Content-Type': 'application/json',
            },
        });

        if (!response.ok) {
            return res.status(response.status).json({ success: false, error: 'Proxy request failed' });
        }

        const data = await response.json();
        return res.status(200).json(data);
    } catch (error) {
        console.error('Transactions Proxy Error:', error);
        return res.status(500).json({ success: false, error: 'Proxy implementation error' });
    }
}
