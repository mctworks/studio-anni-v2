import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { account } from './api.js';
import { formatWhen } from '../comments/CommentItem.jsx';
import './account.css';
import '../comments/comments.css';

const STATUS = {
  pending: 'Awaiting approval',
  approved: 'Approved',
  rejected: 'Not approved',
};

export default function MyReplies() {
  const [items, setItems] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let off = false;
    account.replies().then((r) => {
      if (off) return;
      if (r.ok) setItems(r.data.data);
      else setError('Could not load your comments.');
    });
    return () => {
      off = true;
    };
  }, []);

  const remove = async (item) => {
    if (!window.confirm('Delete this? This cannot be undone.')) return;
    const r = await account.deleteReply(item.kind, item.id);
    if (r.ok) setItems((cur) => cur.filter((x) => !(x.kind === item.kind && x.id === item.id)));
    else setError('That could not be deleted.');
  };

  return (
    <section className="account-replies">
      <h2>My Comments</h2>
      {error && <p className="account-error" role="alert">{error}</p>}
      {items === null && !error && <p>Loading…</p>}
      {items?.length === 0 && <p className="account-note">You haven't posted anything yet.</p>}
      {items?.length > 0 && (
        <ul className="account-reply-list">
          {items.map((item) => (
            <li key={`${item.kind}-${item.id}`} className="account-reply">
              <p className="account-reply__head">
                <strong>{item.kind === 'guestbook' ? 'Guestbook' : <>Comment on <Link to={`/pieces/${item.piece.id}-${item.piece.slug}`}>{item.piece.title}</Link></>}</strong>
                <span className={`account-reply__status account-reply__status--${item.status}`}>{STATUS[item.status]}</span>
                <time dateTime={item.createdAt}>{formatWhen(item.createdAt)}</time>
                {item.kind === 'piece' && item.status === 'approved' && <span>♥ {item.likes}</span>}
              </p>
              <p className="reply__body">{item.body}</p>
              <button className="account-link" type="button" onClick={() => remove(item)}>Delete</button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
