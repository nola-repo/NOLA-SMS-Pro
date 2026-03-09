import type { VercelRequest, VercelResponse } from '@vercel/node';

const GHL_API_URL = "https://services.leadconnectorhq.com";
const GHL_API_TOKEN = "pit-c160b778-6b64-400d-b35b-e0be30dfee8c";
const LOCATION_ID = "ugBqfQsPtGijLjrmLdmA";

export default async function handler(req: VercelRequest, res: VercelResponse) {
  // Set CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Webhook-Secret');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  try {
    if (req.method === 'GET') {
      // Use GET to /contacts with query params
      const contactsUrl = `${GHL_API_URL}/contacts?locationId=${LOCATION_ID}&limit=100`;
      
      console.log('Calling GHL contacts with GET...');
      const response = await fetch(contactsUrl, {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${GHL_API_TOKEN}`,
          'Content-Type': 'application/json',
          'Version': '2021-07-28'
        }
      });

      if (!response.ok) {
        const errorText = await response.text();
        console.error('GHL API Error:', response.status, errorText);
        return res.status(response.status).json({ 
          error: 'Failed to fetch contacts from GHL',
          details: errorText 
        });
      }

      const data = await response.json();
      console.log('GHL API Success, contacts:', data.contacts?.length || 0);
      
      // Transform GHL contacts to our format
      const contacts = (data.contacts || []).map((contact: any) => ({
        id: contact.id,
        name: contact.name || contact.firstName + ' ' + (contact.lastName || ''),
        phone: contact.phone || contact.mobileNumber || '',
        email: contact.email || '',
      })).filter((c: any) => c.phone);

      return res.status(200).json(contacts);
    } else {
      return res.status(405).json({ error: 'Method not allowed' });
    }
  } catch (error) {
    console.error('GHL Contacts Error:', error);
    return res.status(500).json({
      error: 'Failed to fetch contacts',
      message: error instanceof Error ? error.message : 'Unknown error'
    });
  }
}
