import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { account } from './account/api.js';
import { useAuth } from './AuthContext.jsx';
import CommentItem, { formatWhen } from './comments/CommentItem.jsx';
import ReplyForm from './comments/ReplyForm.jsx';
import './GuestbookPage.css';

const PAGE = 20;
const MAX = 2000;

const STATUS_NOTE = {
  pending: 'Your entry is awaiting approval and will appear here once we have reviewed it.',
  approved: 'Your entry is on the page. Thank you for signing!',
  rejected: 'Your entry was not approved.',
};

export default function GuestbookPage() {
  const { user } = useAuth();
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [mine, setMine] = useState(undefined);

  const load = useCallback(async (offset, signal) => {
    const r = await fetch(`/api/guestbook?limit=${PAGE}&offset=${offset}`, { signal });
    if (!r.ok) throw new Error('failed');
    return r.json();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    load(0, controller.signal)
      .then((d) => {
        setItems(d.data);
        setTotal(d.meta.total);
      })
      .catch((e) => {
        if (e.name !== 'AbortError') setFailed(true);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [load]);

  const refreshMine = useCallback(() => {
    if (!user) {
      setMine(undefined);
      return Promise.resolve();
    }
    return account.replies().then((r) => {
      if (r.ok) setMine(r.data.data.find((x) => x.kind === 'guestbook') ?? null);
    });
  }, [user]);

  useEffect(() => {
    refreshMine();
  }, [refreshMine]);

  const more = async () => {
    try {
      const d = await load(items.length);
      setItems((cur) => [...cur, ...d.data]);
      setTotal(d.meta.total);
    } catch {
      setFailed(true);
    }
  };

  return (
    <section className="container guestbook">
      <h1>Guestbook</h1>
      <p className="guestbook__intro">Whether you're a first-time visitor, or a customer who has been with us for years, Anni would love to hear from you. Feel free to sign the guestbook!</p>

      <div className="guestbook__sign">
        {!user && <p><Link to="/login">Sign in</Link> to sign the guestbook.</p>}
        {user && mine === undefined && <p className="catalog-message">Loading…</p>}
        {user && mine === null && (
          <ReplyForm max={MAX} label="Your message" submitLabel="Sign the guestbook" rows={6} send={(body) => account.postGuestbook(body)} onPosted={refreshMine} />
        )}
        {user && mine && (
          <p role="status">{STATUS_NOTE[mine.status]} <span className="guestbook__when">Signed {formatWhen(mine.createdAt)}.</span> <Link to="/account">Manage on your account page</Link></p>
        )}
      </div>

      {loading && <p className="catalog-message">Loading entries…</p>}
      {failed && <p className="catalog-message">The guestbook is temporarily unavailable.</p>}
      {!loading && !failed && items.length === 0 && <p className="catalog-message">No entries yet. Be the first to sign!</p>}

      {items.length > 0 && (
        <ul className="reply-list">
          {items.map((item) => <CommentItem key={item.id} item={item} />)}
        </ul>
      )}

      {items.length < total && <button className="page-nav-button" type="button" onClick={more}>Show more entries</button>}
    </section>
  );
}
