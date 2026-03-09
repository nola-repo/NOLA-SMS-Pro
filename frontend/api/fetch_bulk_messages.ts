import type { VercelRequest, VercelResponse } from '@vercel/node';

const CLOUD_RUN_URL = "https://smspro-api.nolacrm.io";
const WEBHOOK_SECRET = "f7RkQ2pL9zV3tX8cB1nS4yW6";

export default async function handler(req: VercelRequest, res: VercelResponse) {
  // Set CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'X-Webhook-Secret, Content-Type');
  res.setHeader('Content-Type', 'application/json');

  // Handle preflight
  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  try {
    // Try different URL paths
    const possibleUrls = [
      `${CLOUD_RUN_URL}/webhook/fetch_bulk_messages.php`,
      `${CLOUD_RUN_URL}/webhook/fetch_bulk_messages`,
      `${CLOUD_RUN_URL}/fetch_bulk_messages.php`,
      `${CLOUD_RUN_URL}/fetch_bulk_messages`,
    ];

    let response: Response | null = null;
    let fallbackErrors: any[] = [];

    for (const url of possibleUrls) {
      console.log('Trying URL:', url);
      try {
        response = await fetch(url, {
          method: 'GET',
          headers: {
            'X-Webhook-Secret': WEBHOOK_SECRET,
          },
        });

        if (response.ok) {
          console.log('Success with URL:', url);
          break;
        } else {
          const status = response.status;
          const text = await response.text();
          console.log('Failed with URL:', url, 'status:', status);
          fallbackErrors.push({ url, status, text: text.substring(0, 200) });
        }
      } catch (urlErr: any) {
        console.log('Error with URL:', url, urlErr);
        fallbackErrors.push({ url, error: urlErr.message });
      }
    }

    if (!response || !response.ok) {
      return res.status(500).json({
        success: false,
        error: 'All URLs failed',
        details: fallbackErrors
      });
    }

    // Get response as text first to check for errors
    const responseText = await response.text();
    console.log('Cloud Run response text:', responseText.substring(0, 500));

    // Try to parse as JSON
    let data;
    try {
      data = JSON.parse(responseText);
    } catch (parseErr) {
      console.error('Failed to parse Cloud Run response as JSON:', parseErr);
      return res.status(500).json({
        success: false,
        error: 'Cloud Run returned non-JSON response',
        message: responseText.substring(0, 200),
      });
    }

    console.log('Cloud Run response:', data);
    return res.status(response.status).json(data);
  } catch (error) {
    console.error('Fetch Bulk Messages Error:', error);
    return res.status(500).json({
      success: false,
      error: 'Failed to fetch bulk messages',
      message: error instanceof Error ? error.message : 'Unknown error',
    });
  }
}
