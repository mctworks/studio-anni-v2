import { Link } from 'react-router-dom';
import Avatar from '../account/Avatar.jsx';
import '../account/account.css';
import './comments.css';

export const formatWhen = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(d);
};

export default function CommentItem({ item, showLikes = false, liked = false, canLike = false, onLike }) {
  const { author } = item;

  return (
    <li className="reply">
      <Avatar src={author.avatar} name={author.displayName} size={48} />
      <div className="reply__main">
        <p className="reply__head">
          {author.username ? <Link className="reply__name" to={`/u/${author.username}`}>{author.displayName}</Link> : <span className="reply__name">{author.displayName}</span>}
          <span className="reply__badge">{author.title}</span>
          {item.owner && <span className="reply__badge reply__badge--owner">Owner</span>}
          <time className="reply__date" dateTime={item.createdAt}>{formatWhen(item.createdAt)}</time>
        </p>
        <p className="reply__body">{item.body}</p>
        {showLikes && (
          <button
            type="button"
            className={`reply__like${liked ? ' reply__like--on' : ''}`}
            aria-pressed={liked}
            disabled={!canLike}
            title={canLike ? undefined : 'Sign in to like comments'}
            onClick={() => onLike?.(item)}
          >
            <span aria-hidden="true">{liked ? '♥' : '♡'}</span> {item.likes}
            <span className="reply__sr"> {item.likes === 1 ? 'like' : 'likes'}</span>
          </button>
        )}
      </div>
    </li>
  );
}
