export interface AgencyWallet {
  balance: number;
  auto_recharge_enabled: boolean;
  auto_recharge_amount: number;
  auto_recharge_threshold: number;
  enforce_master_balance_lock: boolean;
  updated_at?: string;
}

export interface Transaction {
  id: string;
  type: 'top_up' | 'gift_sent' | 'gift_received' | 'auto_recharge' | 'request_approved' | 'credit_distribution';
  amount: number;
  balance_after: number;
  description: string;
  timestamp: string;
  reference_id?: string;
  transaction_reference_id?: string;
  request_reference_id?: string;
  transfer_reference_id?: string;
  location_name?: string;
}

export interface CreditRequest {
  request_id: string;
  reference_id?: string;
  request_reference_id?: string;
  location_id: string;
  location_name: string;
  amount: number;
  note: string;
  status: 'pending' | 'approved' | 'denied' | 'rejected';
  created_at: string;
}

export interface Subaccount {
  location_id: string;
  location_name: string;
  credit_balance: number;
}

export interface CreditPackage {
  credits: number;
  price: number;
  link: string;
}

export type CreditRequestFilter = 'all' | 'pending' | 'approved' | 'rejected';
