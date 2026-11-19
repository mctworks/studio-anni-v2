import { useState } from 'react';
import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { useNavigate } from 'react-router-dom';
import { useTheme } from '../ThemeContext.jsx';
import { money } from '../commissionStatus.js';
import { stripeFor } from './stripe.js';

function Inner({ session }) {
  const stripe = useStripe();
  const elements = useElements();
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const done = `/payment/complete?payment=${session.payment_id}`;

  const submit = async (e) => {
    e.preventDefault();
    if (!stripe || !elements) return;
    setBusy(true);
    setError('');
    const { error: err } = await stripe.confirmPayment({
      elements,
      confirmParams: { return_url: `${window.location.origin}${done}` },
      redirect: 'if_required',
    });
    if (err) {
      setError(err.message ?? 'Your payment could not be completed.');
      setBusy(false);
      return;
    }
    navigate(done);
  };

  return (
    <form className="pay-form" onSubmit={submit}>
      <PaymentElement />
      {error && <small className="account-error" role="alert">{error}</small>}
      <button className="page-nav-button" type="submit" disabled={!stripe || busy}>{busy ? 'Processing…' : `Pay ${money(session.amount_cents)}`}</button>
    </form>
  );
}

export default function PayForm({ session }) {
  const { isDarkMode } = useTheme();

  return (
    <Elements
      stripe={stripeFor(session.publishable_key)}
      options={{ clientSecret: session.client_secret, appearance: { theme: isDarkMode ? 'night' : 'stripe', variables: { colorPrimary: '#733294' } } }}
    >
      <Inner session={session} />
    </Elements>
  );
}
