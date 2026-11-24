import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { account } from '../account/api.js';
import { useAuth } from '../AuthContext.jsx';
import CommentItem from './CommentItem.jsx';
import ReplyForm from './ReplyForm.jsx';
import './comments.css';

const PAGE = 20;
const MAX = 500;

export default function PieceComments({ pieceId }) {
  const { user } = useAuth();
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [liked, setLiked] = useState(() => new Set());

  const load = useCallback(async (offset, signal) => {
    const r = await fetch(`/api/pieces/${pieceId}/comments?limit=${PAGE}&offset=${offset}`, { signal });
    if (!r.ok) throw new Error('failed');
    return r.json();
  }, [pieceId]);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setFailed(false);
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

  useEffect(() => {
    setLiked(new Set());
    if (!user) return undefined;
    let off = false;
    account.likedComments(pieceId).then((r) => {
      if (!off && r.ok) setLiked(new Set(r.data.ids));
    });
    return () => {
      off = true;
    };
  }, [user, pieceId]);

  const more = async () => {
    try {
      const d = await load(items.length);
      setItems((cur) => [...cur, ...d.data]);
      setTotal(d.meta.total);
    } catch {
      setFailed(true);
    }
  };

  const toggle = async (item) => {
    const on = !liked.has(item.id);
    const r = await (on ? account.likeComment(item.id) : account.unlikeComment(item.id));
    if (!r.ok) return;
    setLiked((cur) => {
      const next = new Set(cur);
      if (on) next.add(item.id);
      else next.delete(item.id);
      return next;
    });
    setItems((cur) => cur.map((c) => (c.id === item.id ? { ...c, likes: r.data.likes } : c)));
  };

  return (
    <section className="replies piece-comments" aria-labelledby="piece-comments-heading">
      <h2 id="piece-comments-heading">Comments{total > 0 ? ` (${total})` : ''}</h2>

      {user ? (
        <ReplyForm
          max={MAX}
          label="Join the conversation"
          submitLabel="Post comment"
          rows={3}
          send={(body) => account.postPieceComment(pieceId, body)}
        />
      ) : (
        <p className="replies__signin"><Link to="/login">Sign in</Link> to leave a comment or like one.</p>
      )}

      {loading && <p className="catalog-message">Loading comments…</p>}
      {failed && <p className="catalog-message">Comments are temporarily unavailable.</p>}
      {!loading && !failed && items.length === 0 && <p className="catalog-message">No comments yet.</p>}

      {items.length > 0 && (
        <ul className="reply-list">
          {items.map((item) => (
            <CommentItem key={item.id} item={item} showLikes liked={liked.has(item.id)} canLike={Boolean(user)} onLike={toggle} />
          ))}
        </ul>
      )}

      {items.length < total && <button className="page-nav-button" type="button" onClick={more}>Show more comments</button>}
    </section>
  );
}
