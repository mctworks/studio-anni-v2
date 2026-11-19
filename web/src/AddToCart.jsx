import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext.jsx';
import { useCart } from './CartContext.jsx';

const errors = {
  unavailable: 'This piece is no longer available.',
  held: 'Someone else is checking out with this piece right now. Please check back in a few minutes.',
  cart_full: 'Your cart is full.',
};

export default function AddToCart({ pieceId }) {
  const { user } = useAuth();
  const cart = useCart();
  const location = useLocation();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const inCart = cart.lines.some((l) => l.id === pieceId);

  if (!user) {
    return <Link className="page-nav-button" to="/login">Sign in to buy</Link>;
  }
  if (inCart) {
    return <Link className="page-nav-button" to="/cart">In your cart · View cart</Link>;
  }

  const click = async () => {
    setBusy(true);
    setError('');
    const r = await cart.add(pieceId);
    setBusy(false);
    if (!r.ok) setError(errors[r.data?.error] ?? 'Could not add to cart. Please try again.');
  };

  return (
    <div className="add-to-cart">
      <button className="page-nav-button" type="button" onClick={click} disabled={busy}>{busy ? 'Adding…' : 'Add to cart'}</button>
      {error && <small className="account-error" role="alert">{error}</small>}
    </div>
  );
}
