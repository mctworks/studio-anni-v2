import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../AuthContext.jsx';
import { useCart } from '../CartContext.jsx';
import { account } from '../account/api.js';
import AddressFields from './AddressFields.jsx';
import PayForm from './PayForm.jsx';
import Totals from './Totals.jsx';
import { money } from '../commissionStatus.js';
import '../account/account.css';
import './checkout.css';

const errorText = {
  empty_cart: 'Your cart is empty.',
  cart_changed: 'Something in your cart changed. Please review your cart.',
  shipping_unavailable: 'We could not price shipping for this order online. Please contact us and we will sort it out.',
  shipping_not_configured: 'Online shipping quotes are not set up right now. Please contact us to complete this order.',
  tax_unavailable: 'We could not calculate sales tax right now. Please try again in a few minutes.',
  rate_limited: 'Too many attempts. Please wait a few minutes.',
  payment_unavailable: 'Payments are unavailable right now. Please try again shortly.',
  terms_required: 'Please accept the terms to continue.',
};

export default function CheckoutPage() {
  const { user } = useAuth();
  const cart = useCart();
  const [method, setMethod] = useState('ship');
  const [address, setAddress] = useState({});
  const [fields, setFields] = useState({});
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState(false);
  const [quote, setQuote] = useState(null);
  const [session, setSession] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (!user) return <div className="container account-page"><p>Please <Link to="/login">sign in</Link> to check out.</p></div>;
  if (cart.loaded && cart.lines.length === 0) return <div className="container account-page"><p>Your cart is empty. <Link to="/gallery">Browse the gallery</Link></p></div>;

  const fail = (r) => {
    setFields(r.data?.fields ?? {});
    if (r.data?.quote) setQuote(r.data.quote);
    if (r.data?.error === 'cart_changed') cart.refresh();
    setError(r.data?.error === 'price_changed' ? 'The total changed. Please review it and continue.' : errorText[r.data?.error] ?? 'Something went wrong. Please try again.');
  };

  const getQuote = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    setFields({});
    const r = await account.checkoutQuote({ method, address });
    setBusy(false);
    if (r.ok) setQuote(r.data.quote);
    else fail(r);
  };

  const pay = async () => {
    setBusy(true);
    setError('');
    const r = await account.checkoutPay({ method: quote.method, address: quote.address, notes, accept_terms: terms, expected_total_cents: quote.total_cents });
    setBusy(false);
    if (r.ok) setSession(r.data);
    else fail(r);
  };

  const edit = () => {
    setQuote(null);
    setSession(null);
    setError('');
  };

  return (
    <div className="container account-page checkout-page">
      <h1>Checkout</h1>
      {!quote && (
        <form className="account-form" onSubmit={getQuote}>
          <fieldset className="checkout-method">
            <legend>Delivery</legend>
            <label className="account-check"><input type="radio" name="method" checked={method === 'ship'} onChange={() => setMethod('ship')} /> Ship to me</label>
            <label className="account-check"><input type="radio" name="method" checked={method === 'pickup'} onChange={() => setMethod('pickup')} /> Local pickup (no shipping fee)</label>
          </fieldset>
          {method === 'ship' && <AddressFields value={address} onChange={setAddress} errors={fields} />}
          {error && <small className="account-error" role="alert">{error}</small>}
          <button className="page-nav-button" type="submit" disabled={busy}>{busy ? 'Calculating…' : 'Review order'}</button>
        </form>
      )}
      {quote && (
        <>
          <ul className="cart-lines">
            {quote.lines.map((l) => (
              <li key={l.id}>
                {l.image ? <img src={l.image} alt="" /> : <span className="cart-line__blank" />}
                <div><span>{l.title}</span><small className="account-note">{l.size}</small></div>
                <strong>{money(l.price_cents)}</strong>
              </li>
            ))}
          </ul>
          <Totals
            rows={[['Subtotal', quote.subtotal_cents], ['Shipping', quote.shipping_cents], ['Sales tax', quote.tax_cents]]}
            total={quote.total_cents}
          />
          <p className="account-note">
            {quote.method === 'pickup' ? 'Local pickup: we will contact you to arrange it.' : `Shipping to ${quote.address.recipient}, ${quote.address.line1}, ${quote.address.city}, ${quote.address.region} ${quote.address.postal_code}`}
          </p>
          {!session && (
            <div className="account-form">
              <label className="account-field">
                Notes (optional)
                <textarea rows={3} maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />
              </label>
              <label className="account-check"><input type="checkbox" checked={terms} onChange={(e) => setTerms(e.target.checked)} /> I agree to the terms of sale</label>
              {error && <small className="account-error" role="alert">{error}</small>}
              <button className="page-nav-button" type="button" disabled={busy || !terms} onClick={pay}>{busy ? 'One moment…' : 'Continue to payment'}</button>
              <button className="checkout-link" type="button" onClick={edit}>Change delivery</button>
            </div>
          )}
          {session && (
            <>
              <p className="account-note">Your pieces are held for 20 minutes while you pay.</p>
              <PayForm session={session} />
            </>
          )}
        </>
      )}
    </div>
  );
}
