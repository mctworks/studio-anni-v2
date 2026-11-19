import { loadStripe } from '@stripe/stripe-js';

const cache = new Map();

export const stripeFor = (key) => {
  if (!cache.has(key)) cache.set(key, loadStripe(key));
  return cache.get(key);
};
