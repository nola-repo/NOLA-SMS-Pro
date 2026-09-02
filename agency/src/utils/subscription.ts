export type SubscriptionPlanId = 'starter' | 'growth' | 'agency' | 'enterprise';
export type SubscriptionStatus = 'active' | 'trialing' | 'cancelled' | 'past_due' | 'expired' | 'inactive';

export interface SubscriptionState {
  plan: SubscriptionPlanId;
  status: SubscriptionStatus;
  subaccount_limit: number;
  subaccounts_used: number;
  total_subaccounts?: number;
  expires_at: string | null;
}

export const PLAN_LIMITS: Record<SubscriptionPlanId, number> = {
  starter: 1,
  growth: 5,
  agency: 25,
  enterprise: -1,
};

export const DEFAULT_SUBSCRIPTION_STATE: SubscriptionState = {
  plan: 'starter',
  status: 'active',
  subaccount_limit: PLAN_LIMITS.starter,
  subaccounts_used: 0,
  expires_at: null,
};

const PLAN_ALIASES: Record<string, SubscriptionPlanId> = {
  starter: 'starter',
  free: 'starter',
  basic: 'starter',
  growth: 'growth',
  pro: 'growth',
  agency: 'agency',
  professional: 'agency',
  enterprise: 'enterprise',
  unlimited: 'enterprise',
};

const STATUS_ALIASES: Record<string, SubscriptionStatus> = {
  active: 'active',
  trial: 'trialing',
  trialing: 'trialing',
  cancelled: 'cancelled',
  canceled: 'cancelled',
  past_due: 'past_due',
  pastdue: 'past_due',
  expired: 'expired',
  inactive: 'inactive',
};

const firstDefined = (...values: unknown[]) =>
  values.find(value => value !== undefined && value !== null && value !== '');

export const normalizeNumber = (value: unknown, fallback: number) => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const normalizePlan = (value: unknown): SubscriptionPlanId | null => {
  const key = String(value ?? '').trim().toLowerCase().replace(/\s+/g, '_');
  return PLAN_ALIASES[key] ?? null;
};

const normalizeStatus = (value: unknown): SubscriptionStatus => {
  const key = String(value ?? '').trim().toLowerCase().replace(/[\s-]+/g, '_');
  return STATUS_ALIASES[key] ?? DEFAULT_SUBSCRIPTION_STATE.status;
};

const normalizeDateString = (value: unknown): string | null => {
  if (!value) return null;
  if (typeof value === 'string') return value;
  if (typeof value === 'object' && value !== null && typeof (value as { seconds?: unknown }).seconds === 'number') {
    return new Date(Number((value as { seconds: number }).seconds) * 1000).toISOString();
  }
  return null;
};

export const normalizeSubscriptionState = (
  payload: unknown,
  options: { fallbackSubaccountsUsed?: number } = {},
): SubscriptionState => {
  const root = payload as any;
  const source = root?.subscription ?? root?.data?.subscription ?? root?.data ?? root ?? {};
  const nestedSubscription = source.subscription ?? {};
  const nestedLimits = source.limits ?? {};
  const rawPlan = firstDefined(
    source.plan,
    source.subscription_plan,
    source.subscriptionPlan,
    source.tier,
    source.subscription_tier,
    source.subscriptionTier,
    source.role,
    nestedSubscription.plan,
    nestedSubscription.subscription_plan,
  );
  const plan = normalizePlan(rawPlan) ?? DEFAULT_SUBSCRIPTION_STATE.plan;
  const planFallbackLimit = PLAN_LIMITS[plan] ?? DEFAULT_SUBSCRIPTION_STATE.subaccount_limit;
  const rawLimit = firstDefined(
    source.subaccount_limit,
    source.subaccountLimit,
    source.plan_subaccount_limit,
    source.planSubaccountLimit,
    source.max_active_subaccounts,
    source.maxActiveSubaccounts,
    source.max_subaccounts,
    source.maxSubaccounts,
    source.limit,
    nestedSubscription.subaccount_limit,
    nestedSubscription.plan_subaccount_limit,
    nestedSubscription.max_active_subaccounts,
    nestedLimits.subaccount_limit,
    nestedLimits.plan_subaccount_limit,
    nestedLimits.max_active_subaccounts,
  );
  const rawUsed = firstDefined(
    source.active_subaccounts,
    source.activeSubaccounts,
    source.enabled_subaccounts,
    source.enabledSubaccounts,
    nestedSubscription.active_subaccounts,
    nestedSubscription.activeSubaccounts,
    nestedLimits.active_subaccounts,
    source.subaccounts_used,
    source.subaccountsUsed,
    source.current_subaccounts,
    source.currentSubaccounts,
    source.used,
    nestedSubscription.subaccounts_used,
    nestedLimits.subaccounts_used,
    options.fallbackSubaccountsUsed,
  );
  const rawTotal = firstDefined(
    source.total_subaccounts,
    source.totalSubaccounts,
    nestedSubscription.total_subaccounts,
    nestedSubscription.totalSubaccounts,
    nestedLimits.total_subaccounts,
  );

  return {
    plan,
    status: normalizeStatus(firstDefined(source.status, source.subscription_status, nestedSubscription.status)),
    subaccount_limit: normalizeNumber(rawLimit, planFallbackLimit),
    subaccounts_used: normalizeNumber(rawUsed, DEFAULT_SUBSCRIPTION_STATE.subaccounts_used),
    total_subaccounts: rawTotal === undefined ? undefined : normalizeNumber(rawTotal, DEFAULT_SUBSCRIPTION_STATE.subaccounts_used),
    expires_at: normalizeDateString(firstDefined(
      source.expires_at,
      source.subscription_expires_at,
      source.renews_at,
      source.current_period_end,
      nestedSubscription.expires_at,
      nestedSubscription.subscription_expires_at,
      nestedSubscription.current_period_end,
    )),
  };
};

export const isUnlimitedSubscription = (limit: unknown) =>
  normalizeNumber(limit, DEFAULT_SUBSCRIPTION_STATE.subaccount_limit) === -1;

export const isSubscriptionActive = (status: unknown) => {
  const normalized = normalizeStatus(status);
  return normalized === 'active' || normalized === 'trialing';
};

export const isSubscriptionLimitReached = (state: SubscriptionState) =>
  !isUnlimitedSubscription(state.subaccount_limit) && state.subaccounts_used >= state.subaccount_limit;

export const getSubscriptionLimitText = (limit: unknown) =>
  isUnlimitedSubscription(limit)
    ? 'Unlimited'
    : normalizeNumber(limit, DEFAULT_SUBSCRIPTION_STATE.subaccount_limit).toString();
