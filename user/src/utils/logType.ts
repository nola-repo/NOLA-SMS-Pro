export function getDisplayLogType(log: {
  type?: string;
  source?: string;
  summary?: string;
  message?: string;
  direction?: string;
}): string {
  // 1. If backend explicitly provided a non-generic type, use it
  if (log.type && log.type !== 'SMS') {
    return log.type;
  }
  // 2. Fallback resolution for historical / unmapped items
  const source = log.source || '';
  const content = log.summary || log.message || '';
  if (source === 'send_sms' || content.includes('Send PH SMS')) {
    return 'Send PH SMS';
  }
  if (source === 'ghl_provider') {
    return 'Conversation Provider';
  }
  if (source === 'inbound' || log.direction === 'inbound') {
    return 'Inbound SMS';
  }
  return log.type || 'SMS';
}
