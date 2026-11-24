import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { account } from './api.js';
import { customerStatus, money } from '../commissionStatus.js';

export default function MyCommissions() {
  const [rows, setRows] = useState(null);

  useEffect(() => {
    let off = false;
    account.commissions().then((r) => {
      if (!off) setRows(r.ok ? r.data.commissions : []);
    });
    return () => {
      off = true;
    };
  }, []);

  if (rows === null) return null;

  return (
    <section className="account-commissions">
      <h2>Your Commissions</h2>
      {rows.length === 0 ? (
        <p className="account-note">You have no commission requests yet. <Link to="/commissions">Learn about commissions</Link></p>
      ) : (
        <table className="account-table">
          <thead><tr><th>Request #</th><th>Portrait</th><th>Estimate</th><th>Status</th></tr></thead>
          <tbody>
            {rows.map((c) => (
              <tr key={c.id}>
                <td><Link to={`/account/commissions/${c.id}`}>Request {c.id}</Link></td>
                <td>{c.subject_type === 'pet' ? 'Pet' : 'Person'} · {c.size}</td>
                <td>{money(c.quoted_cents)}</td>
                <td>{customerStatus[c.status] ?? c.status}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
