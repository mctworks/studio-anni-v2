import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from './api.js';
import { centsToDollars, formatDate } from './util.js';
import { STATUS_LABEL } from './CommissionsHome.jsx';

const SALE_LABEL = { pending: 'Pending', paid: 'Paid', shipped: 'Shipped', cancelled: 'Cancelled' };
const PAGE = 50;

export default function OrdersList() {
  const [kind, setKind] = useState('');
  const [status, setStatus] = useState('');
  const [offset, setOffset] = useState(0);
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let off = false;
    const q = new URLSearchParams({ offset: String(offset) });
    if (kind) q.set('kind', kind);
    if (status) q.set('status', status);
    api
      .get(`/orders?${q}`)
      .then((d) => {
        if (!off) {
          setData(d);
          setError('');
        }
      })
      .catch((e) => {
        if (!off) setError(`Could not load orders (${e.status ?? 'network'}).`);
      });
    return () => {
      off = true;
    };
  }, [kind, status, offset]);

  const label = (o) => (o.kind === 'commission' ? STATUS_LABEL[o.status] : SALE_LABEL[o.status]) ?? o.status;

  return (
    <section>
      <div className="mim-bar"><h2>Orders</h2></div>
      <div className="mim-filters">
        <select value={kind} onChange={(e) => { setKind(e.target.value); setStatus(''); setOffset(0); }} aria-label="Order type">
          <option value="">All orders</option>
          <option value="commission">Commissions</option>
          <option value="sale">Gallery sales</option>
        </select>
        <select value={status} onChange={(e) => { setStatus(e.target.value); setOffset(0); }} aria-label="Status">
          <option value="">Any status</option>
          {(kind === 'sale' ? Object.entries(SALE_LABEL) : Object.entries(STATUS_LABEL)).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </div>
      {error && <p className="mim-error" role="alert">{error}</p>}
      {!data ? <p>Loading…</p> : data.orders.length === 0 ? <p className="mim-note">No orders yet.</p> : (
        <table className="mim-table">
          <thead><tr><th>#</th><th>Type</th><th>Item</th><th>Customer</th><th>Total</th><th>Paid</th><th>Status</th><th>Placed</th></tr></thead>
          <tbody>
            {data.orders.map((o) => (
              <tr key={o.id}>
                <td>{o.commission_id ? <Link to={`/mimosa/commissions/${o.commission_id}`}>{o.id}</Link> : o.id}</td>
                <td>{o.kind === 'commission' ? 'Commission' : 'Sale'}</td>
                <td>{o.item}</td>
                <td>{o.customer}</td>
                <td>${centsToDollars(o.total_cents)}</td>
                <td>${centsToDollars(o.paid_cents)}</td>
                <td>{label(o)}</td>
                <td>{formatDate(o.created_at)}</td>
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
    </section>
  );
}
