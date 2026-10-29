import { useEffect, useMemo, useState } from 'react';

const FEED_LIMIT = 25;
const POPULAR_LIMIT = 5;
const API_URL = '/api/bsky/feed';

const calculateRcbScore = (post) => {
  const likes = post.likeCount || 0;
  const replies = post.replyCount || 0;
  const reposts = post.repostCount || 0;
  let score = likes * 2 + replies * 3 + reposts * 5;
  score += Math.floor(likes / 10) * 7;
  score += Math.floor(replies / 25) * 25;
  score += Math.floor(reposts / 5) * 20;

  return Math.max(0, score);
};

const getPostUrl = (post) => {
  const rkey = post?.uri?.split('/').pop();
  return post?.author?.handle && rkey
    ? `https://bsky.app/profile/${post.author.handle}/post/${rkey}`
    : 'https://bsky.app';
};

const getEmbeds = (embed) => {
  if (!embed) return [];
  if (embed.$type === 'app.bsky.embed.recordWithMedia#view') {
    return [embed.media, embed.record].filter(Boolean);
  }
  return [embed];
};

const PostMedia = ({ embed }) => getEmbeds(embed).map((item, index) => {
  if (item?.$type === 'app.bsky.embed.images#view') {
    return (
      <div className="bluesky-post-images" key={`images-${index}`}>
        {item.images?.map((image) => (
          <a href={image.fullsize} target="_blank" rel="noreferrer" key={image.fullsize}>
            <img src={image.thumb || image.fullsize} alt={image.alt || 'Bluesky post image'} loading="lazy" />
          </a>
        ))}
      </div>
    );
  }

  if (item?.$type === 'app.bsky.embed.video#view') {
    return (
      <a className="piece-detail__visual" href={item.playlist || item.thumbnail || '#'} target="_blank" rel="noreferrer" key={`video-${index}`}>
        {item.thumbnail && <img src={item.thumbnail} alt="Video thumbnail" loading="lazy" />}
        <span>▶ Open video</span>
      </a>
    );
  }

  if (item?.$type === 'app.bsky.embed.external#view' && item.external?.uri) {
    return (
      <a className="purchase-intro" href={item.external.uri} target="_blank" rel="noreferrer" key={`external-${index}`}>
        {item.external.thumb && <img src={item.external.thumb} alt="" loading="lazy" />}
        <span>
          <strong>{item.external.title || item.external.uri}</strong>
          {item.external.description && <small>{item.external.description}</small>}
        </span>
      </a>
    );
  }

  if (item?.$type === 'app.bsky.embed.record#view' && (item.record?.value || item.record?.record?.value)) {
    const quoted = item.record.record || item.record;
    return (
      <blockquote className="purchase-intro" key={`quote-${index}`}>
        <strong>@{quoted.author?.handle || 'Bluesky user'}</strong>
        {quoted.value.text && <p>{quoted.value.text}</p>}
      </blockquote>
    );
  }

  return null;
});

const RcbScore = ({ post }) => {
  const score = calculateRcbScore(post);
  return (
    <div>
      <span>RCB Score</span>
      <strong>{score}</strong>
    </div>
  );
};

const BlueskyFeed = () => {
  const [posts, setPosts] = useState([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [showPopular, setShowPopular] = useState(false);
  const [status, setStatus] = useState('loading');

  useEffect(() => {
    const controller = new AbortController();
    fetch(API_URL, { signal: controller.signal })
      .then((response) => {
        if (!response.ok) throw new Error('Bluesky feed request failed');
        return response.json();
      })
      .then((data) => {
        if (controller.signal.aborted) return;
        setPosts(Array.isArray(data.feed) ? data.feed.slice(0, FEED_LIMIT) : []);
        setStatus(Array.isArray(data.feed) && data.feed.length ? 'ready' : 'empty');
      })
      .catch((error) => {
        if (error.name !== 'AbortError') setStatus('error');
      });

    return () => controller.abort();
  }, []);

  const popularPosts = useMemo(() => [...posts]
    .sort((a, b) => calculateRcbScore(b.post) - calculateRcbScore(a.post))
    .slice(0, POPULAR_LIMIT), [posts]);

  const changePost = (direction) => {
    setCurrentIndex((index) => Math.min(Math.max(index + direction, 0), posts.length - 1));
  };

  if (status === 'loading') {
    return <p className="catalog-message" role="status">Loading Bluesky posts…</p>;
  }

  if (status === 'error') {
    return (
      <p className="catalog-message">
        The Bluesky feed is temporarily unavailable.{' '}
        <a href="https://bsky.app" target="_blank" rel="noreferrer">Visit Bluesky</a>.
      </p>
    );
  }

  if (status === 'empty') {
    return <p className="catalog-message">No Bluesky posts are available right now.</p>;
  }

  if (showPopular) {
    return (
      <section className="bsky-lb" aria-label="Popular Bluesky posts">
        <div className="bsky-latest">
          <h4>Popular posts</h4>
          <button type="button" className="bsky-lb-button" onClick={() => setShowPopular(false)}>
            Back to latest
          </button>
        </div>
        <ol className="bsky-lb-list">
          {popularPosts.map(({ post }, index) => {
            const quotedRecord = post.embed?.record?.record || post.embed?.record;
            const quotedAuthor = quotedRecord?.author;
            const imageAlt = post.embed?.images?.[0]?.alt
              || post.embed?.media?.images?.[0]?.alt;
            const snippet = post.record?.text
              || imageAlt
              || '[THIS POST CONTAINS NO TEXT]';
            const positionClass = index === 0
              ? 'first-place-li'
              : index < 3
                ? 'runnerup-li'
                : 'bottom10-li';
            const positionName = index === 0
              ? 'first'
              : index < 3
                ? 'runnerup'
                : 'bottom10';

            return (
              <li className={positionClass} key={post.uri}>
                {post.author?.avatar && (
                  <img
                    className={`author-avatar ${positionName}-author-avatar`}
                    src={post.author.avatar}
                    alt={`${post.author.handle}'s avatar`}
                    width="48"
                    height="48"
                    loading="lazy"
                  />
                )}
                {quotedAuthor?.avatar && quotedAuthor.handle !== post.author?.handle && (
                  <img
                    className={`author-avatar ${positionName}-author-avatar`}
                    src={quotedAuthor.avatar}
                    alt={`${quotedAuthor.handle}'s avatar`}
                    width="48"
                    height="48"
                    loading="lazy"
                  />
                )}
                <span className="bsky-lb-date">
                  {new Date(post.record?.createdAt).toLocaleDateString()}
                </span>
                <div className="bsky-lb-entry-wrapper">
                  <div className="bsky-lb-user-deets">
                    <p className={`bsky-lb-handle ${positionName}-handle`}>
                      <a
                        href={`https://bsky.app/profile/${post.author?.handle || ''}`}
                        target="_blank"
                        rel="noreferrer"
                      >
                        @{post.author?.handle || 'Bluesky user'}
                      </a>
                    </p>
                    {quotedAuthor?.handle && quotedAuthor.handle !== post.author?.handle && (
                      <p className={`bsky-lb-handle ${positionName}-handle`}>
                        <a
                          href={`https://bsky.app/profile/${quotedAuthor.handle}`}
                          target="_blank"
                          rel="noreferrer"
                        >
                          @{quotedAuthor.handle}
                        </a>
                      </p>
                    )}
                    <a
                      className="bsky-lb-postsnip"
                      href={getPostUrl(post)}
                      target="_blank"
                      rel="noreferrer"
                    >
                      {snippet.slice(0, 120)}
                    </a>
                  </div>
                  <div className="bsky-lb-score-area">
                    <p className={`bsky-lb-score ${positionName}-score`}>
                      RCB SCORE: {calculateRcbScore(post)}
                    </p>
                  </div>
                </div>
              </li>
            );
          })}
        </ol>
        <div className="bsky-lb-bottom">
          <p className="bsky-text">
            Popular posts are ranked by RCB Score among the latest {posts.length} posts.
          </p>
        </div>
      </section>
    );
  }

  const feedItem = posts[currentIndex];
  const post = feedItem.post;
  const postUrl = getPostUrl(post);
  const isLabeled = Boolean(post.labels?.length || post.record?.labels?.length);

  return (
    <section aria-label="Latest Bluesky posts">
      <article className="purchase-intro">
        <header>
          {post.author?.avatar && <img src={post.author.avatar} alt="" width="48" height="48" loading="lazy" />}
          <div>
            <a href={post.author?.handle ? `https://bsky.app/profile/${post.author.handle}` : 'https://bsky.app'} target="_blank" rel="noreferrer">
              {post.author?.displayName || post.author?.handle || 'Bluesky user'}
            </a>
            <time dateTime={post.record?.createdAt}>
              {new Date(post.record?.createdAt).toLocaleString()}
            </time>
          </div>
        </header>

        {feedItem.reply?.parent?.author?.handle && (
          <p>Replying to @{feedItem.reply.parent.author.handle}</p>
        )}
        {post.record?.text && <p>{post.record.text}</p>}
        {isLabeled ? (
          <p>This post has a content label. Open it on Bluesky to view the labeled content.</p>
        ) : <PostMedia embed={post.embed} />}

        <footer>
          <a className="page-nav-button" href={postUrl} target="_blank" rel="noreferrer">View on Bluesky</a>
          <RcbScore post={post} />
        </footer>
      </article>

      <div className="bluesky-pagination">
        <div className="bsky-pg-controls" aria-label="Bluesky Post Pagination">
          <button type="button" className="page-nav-button" onClick={() => changePost(1)} disabled={currentIndex >= posts.length - 1}>← Previous</button>
          <span className="bsky-pg-count">{currentIndex + 1} of {posts.length}</span>
          <button type="button" className="page-nav-button" onClick={() => changePost(-1)} disabled={currentIndex === 0}>Next →</button>
        </div>
        <button type="button" className="page-nav-button" onClick={() => setShowPopular(true)}>Popular posts</button>
      </div>
    </section>
  );
};

export default BlueskyFeed;
