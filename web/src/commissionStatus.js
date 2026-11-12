export const availabilityCopy = {
  open: { label: 'Accepting commissions', tone: 'open' },
  delays: { label: 'Accepting commissions · current delays', tone: 'delays' },
  closed: { label: 'Commissions temporarily closed', tone: 'closed' },
  unknown: { label: 'Commission availability to be announced', tone: 'unknown' },
};

export const money = (cents) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format((Number(cents) || 0) / 100);
