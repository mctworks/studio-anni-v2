export const availabilityCopy = {
  open: { label: 'Accepting commissions', tone: 'open' },
  delays: { label: 'Accepting commissions · current delays', tone: 'delays' },
  closed: { label: 'Commissions temporarily closed', tone: 'closed' },
  unknown: { label: 'Commission availability to be announced', tone: 'unknown' },
};

export const money = (cents) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format((Number(cents) || 0) / 100);

export const customerStatus = {
  submitted: 'Received',
  info_requested: 'Anni has a question',
  customer_replied: 'Reply sent',
  quoted: 'Approved, awaiting payment',
  deposit_paid: 'Payment received',
  in_progress: 'In progress',
  completed: 'Finished',
  awaiting_balance: 'Balance due',
  shipped: 'Shipped',
  denied: 'Commission Denied',
  abandoned: 'Closed',
  stopped: 'Stopped',
  refunded: 'Refunded',
};
