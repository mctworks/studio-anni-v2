import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { account } from './account/api.js';
import { clearCsrf } from './mimosa/api.js';

const KEY = 'sa_user';
const Ctx = createContext(null);

export const isStaff = (u) => u?.role === 'owner' || u?.role === 'admin';

const hint = {
  get() {
    try {
      return localStorage.getItem(KEY) === '1';
    } catch {
      return false;
    }
  },
  set(on) {
    try {
      if (on) localStorage.setItem(KEY, '1');
      else localStorage.removeItem(KEY);
    } catch {
      // storage unavailable
    }
  },
};

export function AuthProvider({ children }) {
  const [user, setUserState] = useState(null);

  const setUser = useCallback((u) => {
    setUserState(u);
    hint.set(Boolean(u));
  }, []);

  useEffect(() => {
    if (!hint.get()) return undefined;
    let off = false;
    account.me().then((r) => {
      if (off) return;
      if (r.ok) setUser(r.data.user);
      else if (r.status === 401 || r.status === 404) hint.set(false);
    });
    return () => {
      off = true;
    };
  }, [setUser]);

  const logout = useCallback(async () => {
    await account.logout();
    clearCsrf();
    setUser(null);
  }, [setUser]);

  const value = useMemo(
    () => ({ user, setUser, logout, staff: isStaff(user) }),
    [user, setUser, logout],
  );

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export const useAuth = () => useContext(Ctx);
