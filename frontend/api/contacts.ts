import type { VercelRequest, VercelResponse } from '@vercel/node';

const GHL_API_URL = "https://services.leadconnectorhq.com";
const GHL_LOCATION_ID = "ugBqfQsPtGijLjrmLdmA";
const GHL_API_TOKEN = "pit-c160b778-6b64-400d-b35b-e0be30dfee8c";

// Transform GHL contact to our format
const transformGHLContact = (contact: any) => ({
  id: contact.id,
  name: contact.name || contact.firstName + ' ' + (contact.lastName || ''),
  phone: contact.phone || contact.mobileNumber || '',
  email: contact.email || '',
});

export default async function handler(req: VercelRequest, res: VercelResponse) {
  // Set CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type');
  res.setHeader('Content-Type', 'application/json');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  try {
    if (req.method === 'GET') {
      // Use GET to /contacts with query params (simpler approach)
      const contactsUrl = `${GHL_API_URL}/contacts?locationId=${GHL_LOCATION_ID}&limit=100`;
      
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
          status: response.status,
          details: errorText 
        });
      }

      const data = await response.json();
      console.log('GHL API Success, contacts:', data.contacts?.length || 0);
      
      // Handle GHL response format
      let contacts: any[] = [];
      if (Array.isArray(data)) {
        contacts = data;
      } else if (data.contacts) {
        contacts = data.contacts;
      } else if (data.data) {
        contacts = data.data;
      }
      
      // Transform to our format
      const transformedContacts = contacts
        .map(transformGHLContact)
        .filter((c: any) => c.phone);
      
      console.log('Transformed contacts:', transformedContacts.length);
      return res.status(200).json(transformedContacts);
    } 
    
    // Add new contact to GHL
    if (req.method === 'POST') {
      const { name, phone, email } = req.body;
      
      if (!name || !phone) {
        return res.status(400).json({ error: 'Name and phone are required' });
      }
      
      // Split name into first and last name
      const nameParts = name.trim().split(' ');
      const firstName = nameParts[0];
      const lastName = nameParts.slice(1).join(' ') || '';
      
      // Format phone number - GHL requires E.164 format (+63 for Philippines)
      const phoneDigits = phone.replace(/\D/g, '');
      const phoneE164 = phoneDigits.startsWith('63') ? '+' + phoneDigits : '+63' + phoneDigits.replace(/^0/, '');
      
      const createUrl = `${GHL_API_URL}/contacts?locationId=${GHL_LOCATION_ID}`;
      
      console.log('Creating contact in GHL:', { name, phone: phoneE164, locationId: GHL_LOCATION_ID });
      
      const response = await fetch(createUrl, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${GHL_API_TOKEN}`,
          'Content-Type': 'application/json',
          'Version': '2021-07-28'
        },
        body: JSON.stringify({
          firstName,
          lastName,
          phone: phoneE164,
          ...(email && { email }),
          locationId: GHL_LOCATION_ID
        })
      });

      if (!response.ok) {
        const errorText = await response.text();
        console.error('GHL API Error creating contact:', response.status, errorText);
        return res.status(response.status).json({ 
          error: 'Failed to create contact in GHL',
          status: response.status,
          details: errorText 
        });
      }

      const data = await response.json();
      console.log('GHL contact created:', data.contact?.id);
      
      // Return the created contact
      const createdContact = transformGHLContact(data.contact);
      return res.status(201).json(createdContact);
    }
    
    // Update existing contact in GHL
    if (req.method === 'PUT') {
      const { id, name, phone, email } = req.body;
      
      if (!id) {
        return res.status(400).json({ error: 'Contact ID is required' });
      }
      
      // Split name into first and last name
      const nameParts = (name || '').trim().split(' ');
      const firstName = nameParts[0] || '';
      const lastName = nameParts.slice(1).join(' ') || '';
      
      // Format phone number - GHL requires E.164 format
      let phoneE164 = '';
      if (phone) {
        const phoneDigits = phone.replace(/\D/g, '');
        phoneE164 = phoneDigits.startsWith('63') ? '+' + phoneDigits : '+63' + phoneDigits.replace(/^0/, '');
      }
      
      const updateUrl = `${GHL_API_URL}/contacts/${id}?locationId=${GHL_LOCATION_ID}`;
      
      console.log('Updating contact in GHL:', { id, name, phone: phoneE164 });
      
      const response = await fetch(updateUrl, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${GHL_API_TOKEN}`,
          'Content-Type': 'application/json',
          'Version': '2021-07-28'
        },
        body: JSON.stringify({
          firstName,
          lastName,
          phone: phoneE164,
          ...(email && { email })
        })
      });

      if (!response.ok) {
        const errorText = await response.text();
        console.error('GHL API Error updating contact:', response.status, errorText);
        return res.status(response.status).json({ 
          error: 'Failed to update contact in GHL',
          status: response.status,
          details: errorText 
        });
      }

      const data = await response.json();
      console.log('GHL contact updated:', data.contact?.id);
      
      // Return the updated contact
      const updatedContact = transformGHLContact(data.contact);
      return res.status(200).json(updatedContact);
    }
    
    // Delete contact from GHL
    if (req.method === 'DELETE') {
      const { id } = req.query;
      
      if (!id || typeof id !== 'string') {
        return res.status(400).json({ error: 'Contact ID is required' });
      }
      
      const deleteUrl = `${GHL_API_URL}/contacts/${id}?locationId=${GHL_LOCATION_ID}`;
      
      console.log('Deleting contact from GHL:', id);
      
      const response = await fetch(deleteUrl, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${GHL_API_TOKEN}`,
          'Content-Type': 'application/json',
          'Version': '2021-07-28'
        }
      });

      if (!response.ok) {
        const errorText = await response.text();
        console.error('GHL API Error deleting contact:', response.status, errorText);
        return res.status(response.status).json({ 
          error: 'Failed to delete contact from GHL',
          status: response.status,
          details: errorText 
        });
      }

      console.log('GHL contact deleted:', id);
      return res.status(200).json({ success: true, id });
    }
    
    return res.status(405).json({ error: 'Method not allowed' });
  } catch (error) {
    console.error('GHL API Error:', error);
    return res.status(500).json({
      error: 'Failed to process contact operation',
      message: error instanceof Error ? error.message : 'Unknown error',
    });
  }
}
