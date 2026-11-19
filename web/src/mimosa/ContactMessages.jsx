import { useCallback, useEffect, useState } from 'react';
import { api } from './api.js';
import { formatDate } from './util.js';

const PAGE = 50;
const KIND_LABEL = { email: 'Email', domain: 'Domain', ip: 'IP address' };
const PLACEHOLDER = { email: 'someone@example.com', domain: 'example.com', ip: '203.0.113.7' };
const ERRORS = {
  invalid: 'That does not look valid.',
  protected_domain: 'That is a common mail provider or this site, so it cannot be blocked.',
  own_ip: 'That is your own address.',
  exists: 'Already blocked.',
  not_found: 'That entry no longer exists.',
};

export default function ContactMessages() {
  const [offset, setOffset] = useState(0);
  const [data, setData] = useState(null);
  const [blocks, setBlocks] = useState(null);
  const [error, setError] = useState('');
  const [kind, setKind] = useState('email');
  const [value, setValue] = useState('');
  const [reason, setReason] = useState('');

  const load = useCallback(async () => {
    try {
      const [m, b] = await Promise.all([api.get(`/contact?offset=${offset}`), api.get('/contact/blocks')]);
      setData(m);
      setBlocks(b.blocks);
    } catch (e) {
      setError(`Could not load contact messages (${e.status ?? 'network'}).`);
    }
  }, [offset]);

  useEffect(() => {
    load();
  }, [load]);

  const run = async (fn) => {
    setError('');
    try {
      await fn();
      await load();
    } catch (e) {
      setError(ERRORS[e.code] || `Something went wrong (${e.status ?? 'network'}).`);
    }
  };

  const ban = (id, k) => run(() => api.post(`/contact/${id}/block`, { kind: k }));
  const remove = (id) => run(() => api.del(`/contact/blocks/${id}`));
  const add = (event) => {
    event.preventDefault();
    run(async () => {
      await api.post('/contact/blocks', { kind, value, reason });
      setValue('');
      setReason('');
    });
  };

  const cell = (m, k, label) => (m.blocked[k]
    ? <span className="mim-muted">{label} blocked</span>
    : <button type="button" className="mim-link" onClick={() => ban(m.id, k)}>Block {label}</button>);

  return (
    <section>
      <div className="mim-bar"><h2>Contact messages</h2></div>
      {error && <p className="mim-error" role="alert">{error}</p>}

      {!data ? <p>Loading…</p> : data.messages.length === 0 ? <p className="mim-note">No messages yet.</p> : (
        <table className="mim-table">
          <thead><tr><th>Received</th><th>From</th><th>Message</th><th>Page</th><th>Block</th></tr></thead>
          <tbody>
            {data.messages.map((m) => (
              <tr key={m.id}>
                <td>{formatDate(m.created_at)}</td>
                <td>{m.name}<br />{m.email}</td>
                <td>{m.excerpt}</td>
                <td>{m.source}</td>
                <td>
                  <div className="mim-actions">
                    {cell(m, 'email', 'email')}
                    {cell(m, 'domain', 'domain')}
                    {cell(m, 'ip', 'IP')}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data && data.total > PAGE && (
        <div className="mim-pager">
          <button type="button" onClick={() => setOffset(Math.max(0, offset - PAGE))} disabled={offset === 0}>Previous</button>
          <span>{offset + 1}-{Math.min(offset + PAGE, data.total)} of {data.total}</span>
          <button type="button" onClick={() => setOffset(offset + PAGE)} disabled={offset + PAGE >= data.total}>Next</button>
        </div>
      )}

      <div className="mim-bar"><h2>Blocked senders</h2></div>
      <form className="mim-filters" onSubmit={add}>
        <select value={kind} onChange={(e) => setKind(e.target.value)} aria-label="Type">
          {Object.entries(KIND_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
        </select>
        <input type="text" required maxLength={254} placeholder={PLACEHOLDER[kind]} value={value} onChange={(e) => setValue(e.target.value)} aria-label="Value to block" />
        <input type="text" maxLength={200} placeholder="Reason (optional)" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Reason" />
        <button type="submit">Block</button>
      </form>
      <p className="mim-note">A blocked sender sees the normal “message sent” screen, but nothing reaches Anni. Blocking a domain also blocks its subdomains.</p>
      {!blocks ? null : blocks.length === 0 ? <p className="mim-note">Nobody is blocked.</p> : (
        <table className="mim-table">
          <thead><tr><th>Type</th><th>Value</th><th>Reason</th><th>Stopped</th><th>Last attempt</th><th /></tr></thead>
          <tbody>
            {blocks.map((b) => (
              <tr key={b.id}>
                <td>{KIND_LABEL[b.kind]}</td>
                <td>{b.value}</td>
                <td>{b.reason}</td>
                <td>{b.hits}</td>
                <td>{formatDate(b.last_hit_at)}</td>
                <td><button type="button" className="mim-link" onClick={() => remove(b.id)}>Unblock</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
