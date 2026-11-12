import { useCallback, useEffect, useId, useState } from 'react';
import { useAuth } from './AuthContext.jsx';
import './ContactForm.css';

const ERRORS = {
  too_fast: 'That was a little quick. Please wait a moment and send again.',
  expired: 'This form timed out. Please send again.',
  invalid_fields: 'Please check your name, email and message (at least a few words).',
  rate_limited: 'Too many messages from here. Please try again later.',
  send_failed: 'Your message could not be sent. Please try again in a bit.',
};

/**
 * Reusable contact form. `source` is one of the sources the API accepts (commissions, about, general) and is only
 * used to tell Anni which page the message came from.
 */
export default function ContactForm({ source = 'general', heading = 'Contact us', intro = 'We will happily answer any questions you may have.', className = '' }) {
  const { user } = useAuth();
  const uid = useId();
  const [token, setToken] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [website, setWebsite] = useState('');
  const [state, setState] = useState('idle');
  const [error, setError] = useState('');

  const loadToken = useCallback(() => {
    fetch('/api/contact/token', { cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('token'))))
      .then((d) => setToken(d.token))
      .catch(() => setToken(''));
  }, []);

  useEffect(loadToken, [loadToken]);

  useEffect(() => {
    if (!user) return;
    setName((n) => n || user.displayName || '');
    setEmail((e) => e || user.email || '');
  }, [user]);

  const submit = async (event) => {
    event.preventDefault();
    if (state === 'sending') return;
    setState('sending');
    setError('');

    try {
      const response = await fetch('/api/contact', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name, email, message, source, token, website }),
      });
      if (response.ok) {
        setState('sent');
        setMessage('');
        return;
      }
      const data = await response.json().catch(() => null);
      setError(ERRORS[data?.error] || 'Something went wrong. Please try again.');
      if (data?.error === 'too_fast' || data?.error === 'expired') loadToken();
    } catch {
      setError('Could not reach the server. Please try again.');
    }
    setState('idle');
  };

  if (state === 'sent') {
    return (
      <section className={`contact-form ${className}`.trim()} aria-live="polite">
        <h2>{heading}</h2>
        <p>Thank you! Your message was sent. We will reply by email.</p>
        <button type="button" className="page-nav-button" onClick={() => { setState('idle'); loadToken(); }}>Send another message</button>
      </section>
    );
  }

  return (
    <section className={`contact-form ${className}`.trim()}>
      <h2>{heading}</h2>
      {intro && <p>{intro}</p>}
      <form onSubmit={submit}>
        <div className="contact-form__trap" aria-hidden="true">
          <label>Leave this field empty<input type="text" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} /></label>
        </div>
        <label htmlFor={`${uid}-name`}>Your name</label>
        <input id={`${uid}-name`} type="text" maxLength={80} required autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} />
        <label htmlFor={`${uid}-email`}>Your email</label>
        <input id={`${uid}-email`} type="email" maxLength={254} required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        <label htmlFor={`${uid}-message`}>Your message</label>
        <textarea id={`${uid}-message`} rows={7} minLength={5} maxLength={4000} required placeholder="Ask us anything!" value={message} onChange={(e) => setMessage(e.target.value)} />
        {error && <p className="contact-form__error" role="alert">{error}</p>}
        <button type="submit" className="page-nav-button" disabled={state === 'sending' || !token}>{state === 'sending' ? 'Sending…' : 'Send message'}</button>
      </form>
    </section>
  );
}
