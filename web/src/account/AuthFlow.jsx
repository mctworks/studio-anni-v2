import { useRef, useState } from 'react';
import { account } from './api.js';
import { clearCsrf } from '../mimosa/api.js';
import '../mimosa/mimosa.css';
import './account.css';

const MESSAGES = {
  invalid_credentials: 'Incorrect email or password.',
  totp_not_enrolled: 'This account has no authenticator set up.',
  invalid_code: 'That code was not accepted.',
  no_pending_login: 'That sign-in timed out. Start again.',
  mail_failed: 'We could not send the email. Use "Send a new code" in a moment.',
  conflict: 'That email or username was just taken.',
  invalid_request: 'Check the fields and try again.',
  csrf: 'Request blocked. Reload and try again.',
  taken: 'Already taken.',
  reserved: 'That name is reserved.',
  invalid: 'Not valid.',
  too_short: 'Use at least 12 characters.',
  too_long: 'Too long.',
  breached: 'That password is on a list of commonly used passwords. Pick another.',
};

const say = (r) => {
  if (r.status === 429) {
    const mins = Math.max(1, Math.ceil((r.data?.retryAfter ?? 60) / 60));
    return `Too many attempts. Try again in ${mins} minute${mins === 1 ? '' : 's'}.`;
  }
  if (!r.status) return 'Could not reach the server.';
  return MESSAGES[r.data?.error] ?? `Something went wrong (${r.status}).`;
};

const fieldSay = (code) => MESSAGES[code] ?? 'Not valid.';

const showSignup = import.meta.env.VITE_SHOW_SIGNUP === 'true';

function PasswordStep({ go, done }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setBusy(true);
    const r = await account.login(email.trim(), password);
    setBusy(false);
    setPassword('');
    const next = { totp_required: 'totp', code_required: 'code', verify_required: 'verify' }[r.data?.status];
    if (next) return go(next, r.ok ? '' : say({ status: 502, data: { error: 'mail_failed' } }));
    if (r.ok && r.data?.user) return done(r.data);
    setError(say(r));
  };

  return (
    <form onSubmit={submit}>
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
      <button type="button" className="mim-link" onClick={() => go('forgot')}>Forgot password?</button>
      {showSignup && <button type="button" className="mim-link" onClick={() => go('register')}>Create an account</button>}
    </form>
  );
}

function ForgotStep({ go }) {
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    const email = new FormData(e.currentTarget).get('email');
    setBusy(true);
    setError('');
    const r = await account.forgot(String(email).trim());
    setBusy(false);
    if (r.ok) setSent(true);
    else setError(say(r));
  };

  if (sent) {
    return (
      <div>
        <p>If that address has an account, we have emailed a link to reset the password. It works once and expires in 1 hour.</p>
        <button type="button" className="mim-link" onClick={() => go('login')}>Back to sign in</button>
      </div>
    );
  }

  return (
    <form onSubmit={submit}>
      <label>
        Email
        <input name="email" type="email" autoComplete="email" required autoFocus />
      </label>
      {error && <p className="mim-error" role="alert">{error}</p>}
      <button type="submit" disabled={busy}>Send reset link</button>
      <button type="button" className="mim-link" onClick={() => go('login')}>Back to sign in</button>
    </form>
  );
}

function RegisterStep({ go }) {
  const openedAt = useRef(Date.now());
  const [errors, setErrors] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setError('');
    setBusy(true);
    const r = await account.register({
      email: f.get('email'),
      username: f.get('username'),
      displayName: f.get('displayName'),
      password: f.get('password'),
      website: f.get('website'),
      t: openedAt.current,
    });
    setBusy(false);
    if (r.status === 422) return setErrors(r.data.fields || {});
    if (r.status === 502) return go('verify', MESSAGES.mail_failed);
    if (!r.ok) return setError(say(r));
    go('verify');
  };

  const err = (k) => errors[k] && <small className="mim-error">{fieldSay(errors[k])}</small>;

  return (
    <form onSubmit={submit}>
      <label>
        Email
        <input name="email" type="email" autoComplete="email" required />
        {err('email')}
      </label>
      <label>
        Username (letters, numbers, underscore; permanent)
        <input name="username" autoComplete="username" minLength={3} maxLength={24} pattern="[A-Za-z0-9_]{3,24}" required />
        {err('username')}
      </label>
      <label>
        Display name
        <input name="displayName" maxLength={80} />
        {err('displayName')}
      </label>
      <label>
        Password (12 characters or more)
        <input name="password" type="password" autoComplete="new-password" minLength={12} required />
        {err('password')}
      </label>
      <div className="account-hp" aria-hidden="true">
        <input name="website" tabIndex={-1} autoComplete="off" />
      </div>
      {error && <p className="mim-error" role="alert">{error}</p>}
      <button type="submit" disabled={busy}>Create account</button>
      <button type="button" className="mim-link" onClick={() => go('login')}>I already have an account</button>
    </form>
  );
}

function EmailCodeStep({ mode, notice, go, done }) {
  const [error, setError] = useState('');
  const [info, setInfo] = useState(notice);
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setBusy(true);
    const r = mode === 'verify' ? await account.verify(f.get('code')) : await account.loginCode(f.get('code'), f.get('trust') === 'on');
    setBusy(false);
    if (r.ok) return done(r.data);
    if (r.data?.error === 'no_pending_login') return go('login', MESSAGES.no_pending_login);
    setInfo('');
    setError(say(r));
  };

  const resend = async () => {
    const r = await account.resend();
    setError('');
    setInfo(r.ok ? 'A new code is on its way.' : say(r));
  };

  return (
    <form onSubmit={submit}>
      <p>{mode === 'verify' ? 'Enter the 6-digit code we emailed you to verify your address.' : 'Enter the 6-digit code we emailed you.'} It expires in 10 minutes.</p>
      <label>
        Code
        <input name="code" inputMode="numeric" autoComplete="one-time-code" maxLength={7} required autoFocus />
      </label>
      {mode === 'login' && (
        <label className="account-check">
          <input name="trust" type="checkbox" /> Trust this browser for 30 days
        </label>
      )}
      {info && <p role="status">{info}</p>}
      {error && <p className="mim-error" role="alert">{error}</p>}
      <button type="submit" disabled={busy}>Continue</button>
      <button type="button" className="mim-link" onClick={resend}>Send a new code</button>
    </form>
  );
}

function TotpStep({ go, done }) {
  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setBusy(true);
    const r = await account.totp(recovery ? { recoveryCode: code.trim() } : { code: code.replace(/\s/g, '') });
    setBusy(false);
    if (r.ok) return done(r.data);
    if (r.data?.error === 'no_pending_login') return go('login', MESSAGES.no_pending_login);
    setError(say(r));
  };

  return (
    <form onSubmit={submit}>
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
  );
}

const TITLES = { forgot: 'Reset your password', login: 'Sign in', totp: 'Sign in', code: 'Check your email', verify: 'Verify your email', register: 'Create an account' };

export default function AuthFlow({ onDone, startAt }) {
  const [step, setStep] = useState(startAt === 'register' ? 'register' : 'login');
  const [notice, setNotice] = useState('');

  const go = (next, msg = '') => {
    setNotice(msg);
    setStep(next);
  };
  const done = (data) => {
    clearCsrf();
    onDone(data.user, data.recoveryCodesRemaining);
  };

  return (
    <main className="mim-login">
      <h1>{TITLES[step]}</h1>
      {step === 'login' && notice && <p role="status">{notice}</p>}
      {step === 'login' && <PasswordStep go={go} done={done} />}
      {step === 'register' && <RegisterStep go={go} />}
      {step === 'forgot' && <ForgotStep go={go} />}
      {step === 'totp' && <TotpStep go={go} done={done} />}
      {(step === 'code' || step === 'verify') && <EmailCodeStep key={step} mode={step === 'code' ? 'login' : 'verify'} notice={notice} go={go} done={done} />}
    </main>
  );
}
