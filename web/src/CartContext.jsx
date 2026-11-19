import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { account } from './account/api.js';
import { useAuth } from './AuthContext.jsx';

const Ctx = createContext(null);

export function CartProvider({ children }) {
  const { user } = useAuth();
  const [cart, setCart] = useState({ lines: [], subtotal_cents: 0 });
  const [loaded, setLoaded] = useState(false);

  const refresh = useCallback(async () => {
    const r = await account.cart();
    if (r.ok) setCart(r.data);
    setLoaded(true);
    return r;
  }, []);

  useEffect(() => {
    if (!user) {
      setCart({ lines: [], subtotal_cents: 0 });
      setLoaded(false);
      return;
    }
    refresh();
  }, [user, refresh]);

  const add = useCallback(async (pieceId) => {
    const r = await account.addToCart(pieceId);
    if (r.ok) setCart(r.data);
    return r;
  }, []);

  const remove = useCallback(async (pieceId) => {
    const r = await account.removeFromCart(pieceId);
    if (r.ok) setCart(r.data);
    return r;
  }, []);

  const value = useMemo(() => ({ ...cart, count: cart.lines.length, loaded, refresh, add, remove }), [cart, loaded, refresh, add, remove]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export const useCart = () => useContext(Ctx);
