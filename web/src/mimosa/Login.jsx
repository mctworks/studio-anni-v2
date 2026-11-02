import { useState } from 'react';
import { api, ApiError } from './api.js';

const MESSAGES = {
  invalid_credentials: 'Incorrect email or password.',
  totp_not_enrolled: 'This account has no authenticator set up.',
  csrf: 'Request blocked (origin or security token). Reload and try again.',
  invalid_request: 'Check the fields and try again.',
  invalid_code: 'That code was not accepted.',
  no_pending_login: 'That sign-in timed out. Start again.',
};

function describe(err) {
  if (!(err instanceof ApiError)) return 'Could not reach the server.';
  if (err.status === 429) {
    const mins = Math.max(1, Math.ceil((err.data?.retryAfter ?? 60) / 60));
    return `Too many attempts. Try again in ${mins} minute${mins === 1 ? '' : 's'}.`;
  }
  return MESSAGES[err.code] ?? `Something went wrong (${err.status}).`;
}

export default function Login({ onLogin }) {
  const [step, setStep] = useState('password');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submitPassword = async (e) => {
    e.preventDefault();
    setError('');
    setBusy(true);
    try {
      const data = await api.post('/login', { email: email.trim(), password });
      setPassword('');
      if (data?.status === 'totp_required') setStep('code');
      else if (data?.user) onLogin(data.user);
    } catch (err) {
      setError(describe(err));
    } finally {
      setBusy(false);
    }
  };

  const submitCode = async (e) => {
    e.preventDefault();
    setError('');
    setBusy(true);
    try {
      const body = recovery ? { recoveryCode: code.trim() } : { code: code.replace(/\s/g, '') };
      const data = await api.post('/login/totp', body);
      onLogin(data.user, data.recoveryCodesRemaining);
    } catch (err) {
      setError(describe(err));
      if (err instanceof ApiError && err.code === 'no_pending_login') {
        setStep('password');
        setCode('');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="mim-login">
      <h1>Sign in</h1>
      {step === 'password' ? (
        <form onSubmit={submitPassword}>
          <label>
            Email
            <input type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
          </label>
          <label>
            Password
            <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </label>
          {error && <p className="mim-error" role="alert">{error}</p>}
          <button type="submit" disabled={busy}>Continue</button>
        </form>
      ) : (
        <form onSubmit={submitCode}>
          <label>
            {recovery ? 'Recovery code' : 'Authenticator code'}
            <input
              type="text"
              inputMode={recovery ? 'text' : 'numeric'}
              autoComplete="one-time-code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              required
              autoFocus
            />
          </label>
          {error && <p className="mim-error" role="alert">{error}</p>}
          <button type="submit" disabled={busy}>Sign in</button>
          <button
            type="button"
            className="mim-link"
            onClick={() => {
              setRecovery(!recovery);
              setCode('');
              setError('');
            }}
          >
            {recovery ? 'Use authenticator code' : 'Use a recovery code'}
          </button>
        </form>
      )}
    </main>
  );
}