# Backend Handoff: Credit Transactions Payload Enhancements

## Context
The frontend has been completely upgraded to generate highly-detailed "Electricity Bill" style Monthly Invoices (PDFs) from the `api/billing/transactions.php` endpoint. 

The invoice is configured to include detailed line items such as the specific **Message Content**, the exact **Recipient Number**, and the exact numeral **Characters Count** for SMS Usage.

## Issue Identified
Currently, the `credit_transactions` fetch payload only yields generic fields (`location_name`, `amount`, `balance_after`, `description`). The actual text content shipped (`message_body`), the formatted number (`to_number`), and the string block length (`chars`) are missing. Our frontend is utilizing regex to parse `"SMS to +NUMBER"` from the generic description as a temporary fallback, but the message itself returns "Unavailable".

Additionally, while `location_name` is present, `agency_name` and `subaccount_name` are not uniformly present on all transaction logs.

## Requested Backend Action
Please modify `api/billing/transactions.php` (or modify the backend logger that saves `credit_transactions` in Firestore natively) to ensure the following fields are attached to the API response for each transaction object where `type == 'sms_usage'`:

- `message_body` (String): The exact body of the text message sent.
- `chars` (Number/String): The total character count of the message payload.
- `to_number` (String): The recipient's formatted phone number (e.g., "+639976871043").
- `agency_name` (String): The descriptive string name of the Agency holding the subaccount.
- `subaccount_name` (String): The descriptive string name of the Location/Subaccount.

Once these fields begin trickling into the frontend payload, the Invoice PDF Generator will automatically snap them into the layout neatly without any further frontend updates!
