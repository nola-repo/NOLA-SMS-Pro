import type { VercelRequest, VercelResponse } from '@vercel/node';

export default async function handler(
  request: VercelRequest,
  response: VercelResponse
) {
  if (request.method !== 'POST') {
    return response.status(405).json({
      status: 'error',
      message: 'Method not allowed'
    });
  }

  try {
    // Parse the form data from request body
    const { number, message, sendername } = request.body || {};

    let formattedNumber = number || '';

    // Format Philippine phone numbers - the webhook expects 09xxxxxxxxx format
    // It will convert +63 to 0 internally
    if (formattedNumber.startsWith('+63')) {
      formattedNumber = '0' + formattedNumber.substring(3);
    } else if (formattedNumber.startsWith('639')) {
      formattedNumber = '0' + formattedNumber.substring(2);
    } else if (formattedNumber.startsWith('09') && formattedNumber.length === 11) {
      // Already in correct format
    } else if (formattedNumber.startsWith('9') && formattedNumber.length === 10) {
      formattedNumber = '0' + formattedNumber;
    }

    // Rebuild the body with the customData wrapper as JSON
    const payload = {
      customData: {
        number: formattedNumber,
        message: message || '',
        sendername: sendername || 'NOLACRM',
      }
    };

    const webhookResponse = await fetch('https://smspro-api.nolacrm.io/webhook/send_sms', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6',
      },
      body: JSON.stringify(payload),
    });

    const data = await webhookResponse.json();

    return response.status(200).json(data);
  } catch (error) {
    console.error('SMS Proxy Error:', error);
    return response.status(500).json({
      status: 'error',
      message: 'Failed to send SMS'
    });
  }
}
