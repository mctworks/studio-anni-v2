import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from './AuthContext.jsx';
import { useCart } from './CartContext.jsx';
import { money } from './commissionStatus.js';
import './checkout/checkout.css';

const reasons = { unavailable: 'No longer available', held: 'Being checked out by someone else' };

export default function CartPage() {
  const { user } = useAuth();
  const cart = useCart();
  const [busy, setBusy] = useState(null);

  useEffect(() => {
    if (user) cart.refresh();
  }, [user]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!user) return <div className="container account-page"><p>Please <Link to="/login">sign in</Link> to see your cart.</p></div>;
  if (!cart.loaded) return <div className="container account-page"><p>Loading…</p></div>;

  const ready = cart.lines.filter((l) => l.ok);
  const remove = async (id) => {
    setBusy(id);
    await cart.remove(id);
    setBusy(null);
  };

  return (
    <div className="container account-page cart-page">
      <h1>Your cart</h1>
      {cart.lines.length === 0 ? (
        <p>Your cart is empty. <Link to="/gallery">Browse the gallery</Link></p>
      ) : (
        <>
          <ul className="cart-lines">
            {cart.lines.map((l) => (
              <li key={l.id} className={l.ok ? '' : 'cart-line--off'}>
                {l.image ? <img src={l.image} alt="" /> : <span className="cart-line__blank" />}
                <div>
                  <Link to={`/pieces/${l.id}-${l.slug}`}>{l.title}</Link>
                  <small className="account-note">{l.size}</small>
                  {!l.ok && <small className="account-error" role="status">{reasons[l.reason]}</small>}
                </div>
                <strong>{money(l.price_cents)}</strong>
                <button type="button" className="cart-remove" onClick={() => remove(l.id)} disabled={busy === l.id} aria-label={`Remove ${l.title}`}>Remove</button>
              </li>
            ))}
          </ul>
          <p className="cart-subtotal">Subtotal <strong>{money(cart.subtotal_cents)}</strong></p>
          <p className="account-note">Shipping and tax are calculated at checkout.</p>
          {ready.length > 0 && <Link className="page-nav-button" to="/checkout">Checkout</Link>}
        </>
      )}
    </div>
  );
}
