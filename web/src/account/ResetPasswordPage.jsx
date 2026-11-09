import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { account } from './api.js';
import '../mimosa/mimosa.css';
import './account.css';

const passwordMessages = {
  too_short: 'Use at least 12 characters.',
  too_long: 'That password is too long.',
  breached: 'That password is on a list of commonly used passwords. Pick another.',
};

export default function ResetPasswordPage() {
  const [token, setToken] = useState(null);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const m = /(?:^#|&)token=([a-f0-9]{64})/.exec(window.location.hash);
    setToken(m ? m[1] : '');
    if (window.location.hash) window.history.replaceState(null, '', window.location.pathname);
  }, []);

  const submit = async (e) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const password = String(f.get('password'));
    if (password !== String(f.get('confirm'))) return setError('The two passwords do not match.');
    setBusy(true);
    setError('');
    const r = await account.resetPassword(token, password);
    setBusy(false);
    if (r.ok) return setDone(true);
    if (r.data?.error === 'invalid_token') return setToken('');
    if (r.status === 429) return setError('Too many attempts. Try again later.');
    setError(passwordMessages[r.data?.fields?.password] ?? 'That password was not accepted.');
  };

  if (token === null) return null;

  return (
    <main className="mim-login">
      <h1>Choose a new password</h1>
      {done ? (
        <p>Your password was changed. <Link to="/login">Sign in</Link></p>
      ) : token === '' ? (
        <p>This link is invalid or has expired. <Link to="/login">Request a new one</Link> with "Forgot password?".</p>
      ) : (
        <form onSubmit={submit}>
          <label>
            New password (12 characters or more)
            <input name="password" type="password" autoComplete="new-password" minLength={12} required autoFocus />
          </label>
          <label>
            Confirm password
            <input name="confirm" type="password" autoComplete="new-password" minLength={12} required />
          </label>
          {error && <p className="mim-error" role="alert">{error}</p>}
          <button type="submit" disabled={busy}>Change password</button>
        </form>
      )}
    </main>
  );
}
