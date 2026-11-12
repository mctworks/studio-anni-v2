import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from './api.js';
import { centsToDollars, dollarsToCents } from './util.js';

const FEES = [
  ['extra_pet_cents', 'Each additional pet (pet portrait)'],
  ['extra_person_cents', 'Each additional person (human portrait)'],
  ['human_pet_cents', 'Each pet added to a human portrait'],
];

const fromCents = (c) => (c === null || c === undefined ? '' : centsToDollars(c));

export default function PricingEditor() {
  const [loaded, setLoaded] = useState(null);
  const [rows, setRows] = useState([]);
  const [fees, setFees] = useState({});
  const [deposit, setDeposit] = useState('50');
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  const apply = (d) => {
    setLoaded(d);
    setRows(d.sizes.map((s) => ({ ...s, pet: fromCents(s.pet_cents), human: fromCents(s.human_cents) })));
    setFees(Object.fromEntries(Object.entries(d.fees).map(([k, v]) => [k, fromCents(v)])));
    setDeposit(String(d.deposit_percent));
  };

  useEffect(() => {
    api.get('/commissions/pricing').then(apply).catch((e) => setError(`Could not load pricing (${e.status ?? 'network'}).`));
  }, []);

  const setCell = (id, key, value) => {
    setRows((rs) => rs.map((r) => (r.canvas_size_id === id ? { ...r, [key]: value } : r)));
    setSaved(false);
  };

  const save = async (e) => {
    e.preventDefault();
    setError('');
    setSaved(false);

    const prices = [];
    for (const r of rows) {
      for (const [key, type, orig] of [['pet', 'pet', r.pet_cents], ['human', 'human', r.human_cents]]) {
        const text = r[key].trim();
        const cents = text === '' ? null : dollarsToCents(text);
        if (text !== '' && cents === null) {
          setError(`"${r[key]}" is not a valid price (${r.label}, ${type}).`);
          return;
        }
        if (cents !== (orig ?? null)) prices.push({ subject_type: type, canvas_size_id: r.canvas_size_id, price_cents: cents });
      }
    }

    const feeCents = {};
    for (const [key, label] of FEES) {
      const cents = dollarsToCents(fees[key] ?? '');
      if (cents === null) {
        setError(`Fee "${label}" is not a valid amount.`);
        return;
      }
      feeCents[key] = cents;
    }

    const percent = Number(deposit);
    if (!Number.isInteger(percent) || percent < 1 || percent > 100) {
      setError('Deposit must be a whole number from 1 to 100.');
      return;
    }

    setBusy(true);
    try {
      apply(await api.put('/commissions/pricing', { prices, fees: feeCents, deposit_percent: percent }));
      setSaved(true);
    } catch (err) {
      setError(`Could not save (${err.status ?? 'network'}).`);
    } finally {
      setBusy(false);
    }
  };

  if (!loaded) return error ? <p className="mim-error" role="alert">{error}</p> : <p>Loading…</p>;

  return (
    <form className="mim-form" onSubmit={save}>
      <div className="mim-bar">
        <h2>Commission pricing</h2>
        <Link to="/mimosa">Back to commissions</Link>
      </div>
      <p className="mim-note">Prices are in dollars, shipping excluded. Leave a price blank to not offer that type at that size. Sizes with the same prices are shown together to customers.</p>

      <table className="mim-table">
        <thead>
          <tr><th>Size</th><th>Pet portrait ($)</th><th>Human portrait ($)</th></tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.canvas_size_id}>
              <td>{r.label}</td>
              <td><input inputMode="decimal" value={r.pet} onChange={(e) => setCell(r.canvas_size_id, 'pet', e.target.value)} aria-label={`${r.label} pet price`} /></td>
              <td><input inputMode="decimal" value={r.human} onChange={(e) => setCell(r.canvas_size_id, 'human', e.target.value)} aria-label={`${r.label} human price`} /></td>
            </tr>
          ))}
        </tbody>
      </table>

      <fieldset>
        <legend><h3>Extra subjects ($)</h3></legend>
        <div className="mim-grid">
          {FEES.map(([key, label]) => (
            <label className="mim-field" key={key}>
              <span>{label}</span>
              <input inputMode="decimal" value={fees[key] ?? ''} onChange={(e) => { setFees({ ...fees, [key]: e.target.value }); setSaved(false); }} />
            </label>
          ))}
          <label className="mim-field">
            <span>Deposit (% of total)</span>
            <input inputMode="numeric" value={deposit} onChange={(e) => { setDeposit(e.target.value); setSaved(false); }} />
          </label>
        </div>
      </fieldset>

      {error && <p className="mim-error" role="alert">{error}</p>}
      <p>
        <button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save pricing'}</button>{' '}
        {saved && <span className="mim-ok" role="status">Saved</span>}
      </p>
    </form>
  );
}
