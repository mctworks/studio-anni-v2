import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from './api.js';
import { centsToDollars, formatDate } from './util.js';
import { STATUS_LABEL } from './CommissionsHome.jsx';

export default function CommissionDetail() {
  const { id } = useParams();
  const [c, setC] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let off = false;
    api
      .get(`/commissions/${id}`)
      .then((d) => {
        if (!off) setC(d.commission);
      })
      .catch((e) => {
        if (!off) setError(e.status === 404 ? 'Commission not found.' : `Could not load this commission (${e.status ?? 'network'}).`);
      });
    return () => {
      off = true;
    };
  }, [id]);

  if (error) return <p className="mim-error" role="alert">{error}</p>;
  if (!c) return <p>Loading…</p>;

  return (
    <section>
      <div className="mim-bar">
        <h2>Commission #{c.id}</h2>
        <Link to="/mimosa">Back to commissions</Link>
      </div>
      <dl className="mim-details">
        <dt>Status</dt><dd>{STATUS_LABEL[c.status] ?? c.status}</dd>
        <dt>Customer</dt><dd>{c.customer} (<a href={`mailto:${c.email}`}>{c.email}</a>) · <Link to={`/mimosa/users/${c.user_id}`}>account</Link></dd>
        <dt>Type</dt><dd>{c.subject_type === 'pet' ? 'Pet / animal' : 'Human'}</dd>
        <dt>Size</dt><dd>{c.size}</dd>
        <dt>Subjects</dt><dd>{c.person_count} people, {c.pet_count} pets</dd>
        <dt>Estimate</dt><dd>${centsToDollars(c.quoted_cents)} plus shipping</dd>
        <dt>Delivery</dt><dd>{c.delivery === 'pickup' ? `Local pickup (ZIP ${c.postal_code})` : 'Ship'}</dd>
        <dt>Requested</dt><dd>{formatDate(c.created_at)}</dd>
        <dt>Terms</dt><dd>{c.terms_version}, accepted {formatDate(c.terms_accepted_at)}</dd>
      </dl>
      <h3>Description</h3>
      <p className="mim-pre">{c.description}</p>
      <h3>Source photos</h3>
      {c.files.length === 0 ? <p className="mim-note">No photos.</p> : (
        <ul className="mim-photos">
          {c.files.map((f) => (
            <li key={f.id}>
              <a href={`/api/mimosa/commissions/${c.id}/files/${f.id}`} target="_blank" rel="noreferrer">
                <img src={`/api/mimosa/commissions/${c.id}/files/${f.id}`} alt={f.original_name} loading="lazy" />
              </a>
              <span className="mim-note">{f.original_name}</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
