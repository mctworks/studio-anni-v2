import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../AuthContext.jsx';
import { useCart } from '../CartContext.jsx';
import { account } from '../account/api.js';
import PayForm from './PayForm.jsx';
import Totals from './Totals.jsx';
import { money } from '../commissionStatus.js';
import '../account/account.css';
import './checkout.css';

const errorText = {
  empty_cart: 'Your cart is empty.',
  invalid_deliveries: 'Choose a saved shipping address for each piece.',
  invalid_address: 'One of the selected saved addresses is unavailable. Please review your address book.',
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
  const [addresses, setAddresses] = useState([]);
  const [addressesLoaded, setAddressesLoaded] = useState(false);
  const [deliveries, setDeliveries] = useState({});
  const [fields, setFields] = useState({});
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState(false);
  const [quote, setQuote] = useState(null);
  const [session, setSession] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    account.addresses().then((r) => {
      if (r.ok) setAddresses(r.data.addresses);
      setAddressesLoaded(true);
    });
  }, []);

  useEffect(() => {
    if (addresses.length === 0 || cart.lines.length === 0) return;
    const defaultId = (addresses.find((a) => a.isDefault) ?? addresses[0]).id;
    setDeliveries((previous) => Object.fromEntries(cart.lines.map((line) => [
      line.id,
      addresses.some((a) => a.id === previous[line.id]) ? previous[line.id] : defaultId,
    ])));
  }, [addresses, cart.lines]);

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
    const assignments = cart.lines.map((line) => ({ piece_id: line.id, address_id: Number(deliveries[line.id]) }));
    if (method === 'ship' && (assignments.some((delivery) => !delivery.address_id) || !addressesLoaded || addresses.length === 0)) {
      setError(addressesLoaded ? 'Add a saved shipping address to your account, then return to checkout.' : 'Loading your saved addresses…');
      return;
    }
    setBusy(true);
    setError('');
    setFields({});
    const r = await account.checkoutQuote({ method, deliveries: method === 'ship' ? assignments : [] });
    setBusy(false);
    if (r.ok) setQuote(r.data.quote);
    else fail(r);
  };

  const pay = async () => {
    setBusy(true);
    setError('');
    const r = await account.checkoutPay({ method: quote.method, deliveries: quote.deliveries, notes, accept_terms: terms, expected_total_cents: quote.total_cents });
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
          {method === 'ship' && (
            <section className="checkout-destinations">
              <h2>Ship each piece to</h2>
              {!addressesLoaded && <p className="account-note">Loading saved addresses…</p>}
              {addressesLoaded && addresses.length === 0 && <p className="account-note">Add a shipping address in <Link to="/account">your account</Link>, then return here.</p>}
              {cart.lines.map((line) => (
                <label className="account-field" key={line.id}>
                  {line.title}
                  <select
                    value={deliveries[line.id] ?? ''}
                    required
                    onChange={(e) => setDeliveries({ ...deliveries, [line.id]: Number(e.target.value) })}
                  >
                    <option value="" disabled>Select a saved address</option>
                    {addresses.map((saved) => (
                      <option key={saved.id} value={saved.id}>
                        {saved.label || saved.recipient} — {saved.line1}, {saved.city}, {saved.region} {saved.postal_code}
                      </option>
                    ))}
                  </select>
                </label>
              ))}
              <p className="account-note"><Link to="/account">Manage saved addresses</Link></p>
            </section>
          )}
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
          <section className="checkout-destinations checkout-destinations--summary">
            <h2>{quote.method === 'pickup' ? 'Pickup' : 'Shipments'}</h2>
            {quote.shipments.map((shipment, i) => (
              <div className="checkout-shipment" key={`${shipment.address_id ?? 'pickup'}-${i}`}>
                {quote.method === 'pickup' ? <strong>Local pickup</strong> : (
                  <>
                    <strong>{shipment.label || shipment.address.recipient}</strong>
                    <span>{shipment.address.recipient}, {shipment.address.line1}{shipment.address.line2 ? `, ${shipment.address.line2}` : ''}, {shipment.address.city}, {shipment.address.region} {shipment.address.postal_code}</span>
                  </>
                )}
                <small className="account-note">{shipment.piece_ids.map((id) => quote.lines.find((line) => line.id === id)?.title).filter(Boolean).join(', ')}</small>
              </div>
            ))}
          </section>
          <Totals
            rows={[['Subtotal', quote.subtotal_cents], ['Shipping', quote.shipping_cents], ['Sales tax', quote.tax_cents]]}
            total={quote.total_cents}
          />
          {quote.method === 'pickup' && <p className="account-note">We will contact you to arrange local pickup.</p>}
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
