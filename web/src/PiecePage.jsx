import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

const PiecePage = () => {
  const navigate = useNavigate();
  const { pieceKey } = useParams();
  const pieceId = pieceKey.split('-')[0];
  const [piece, setPiece] = useState(null);
  const [similarPieces, setSimilarPieces] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    fetch(`/api/pieces/${encodeURIComponent(pieceKey)}`, { signal: controller.signal })
      .then((response) => {
        if (!response.ok) throw new Error('Piece could not be loaded');
        return response.json();
      })
      .then((result) => setPiece(result.data))
      .catch((reason) => {
        if (reason.name !== 'AbortError') setError(true);
      })
      .finally(() => setLoading(false));

    return () => controller.abort();
  }, [pieceId]);

  const hasPeopleCategory = piece?.categories?.some((category) => category.slug === 'people') ?? false;
  const similarCategory = piece?.categories?.find((category) => !['people', 'hands'].includes(category.slug))?.slug;
  const showSimilar = piece && piece.status !== 'available' && piece.origin !== 'commission' && !hasPeopleCategory && similarCategory;

  useEffect(() => {
    if (!showSimilar) {
      setSimilarPieces([]);
      return undefined;
    }

    const controller = new AbortController();
    const params = new URLSearchParams({ status: 'available', origin: 'original', category: similarCategory, sort: 'newest', limit: '4' });
    fetch(`/api/pieces?${params}`, { signal: controller.signal })
      .then((response) => {
        if (!response.ok) throw new Error('Similar artwork could not be loaded');
        return response.json();
      })
      .then((result) => setSimilarPieces(result.data.filter((item) => item.id !== piece.id)))
      .catch((reason) => {
        if (reason.name !== 'AbortError') setSimilarPieces([]);
      });

    return () => controller.abort();
  }, [showSimilar, similarCategory, piece?.id]);

  if (loading) {
    return <section className="container content-page piece-page"><p className="catalog-message">Loading artwork…</p></section>;
  }

  if (error || !piece) {
    return (
      <section className="container content-page piece-page">
        <h1>Artwork not found</h1>
        <p>This piece may no longer be available.</p>
        <button className="page-nav-button" type="button" onClick={() => navigate('/')}>Return to the homepage <span aria-hidden="true">→</span></button>
      </section>
    );
  }

  const price = piece.sale_price_cents ?? piece.price_cents;
  const formattedPrice = price == null ? null : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price / 100);

  return (
    <article className="container content-page piece-page">
      <button className="page-nav-button piece-page__back" type="button" onClick={() => navigate('/')}>← Back to Studio Anni</button>
      <div className="piece-detail">
        <div className="piece-detail__visual">
          {piece.image?.url ? <img src={piece.image.url} alt={piece.image.alt || piece.title} /> : <div className="piece-card__image--empty" />}
        </div>
        <div className="piece-detail__copy">
          <p className="eyebrow">{piece.status === 'available' ? 'Original artwork' : piece.status === 'on_display' ? 'Currently on display' : 'Past work'}</p>
          <h1>{piece.title}</h1>
          <p className="piece-detail__meta">{piece.size}{piece.medium ? ` · ${piece.medium}` : ''}</p>
          {piece.status === 'available' && formattedPrice && <p className="piece-detail__price">{formattedPrice}</p>}
          {piece.status_note && <p className="piece-detail__note">{piece.status_note}</p>}
          {piece.description && <p className="piece-detail__description">{piece.description}</p>}
          {piece.origin === 'commission' && (
            <p className="piece-detail__commission">Interested in a portrait commission? <span>TODO: link to the Commissions page once it exists.</span></p>
          )}
          {piece.images?.length > 1 && (
            <div className="piece-detail__angles" aria-label="Additional views">
              {piece.images.slice(1).map((image) => <img key={image.url} src={image.url} alt={image.alt || `${piece.title}, additional view`} loading="lazy" />)}
            </div>
          )}
        </div>
      </div>
      {showSimilar && similarPieces.length > 0 && (
        <section className="archive-similar" aria-labelledby="archive-similar-heading">
          <h2 id="archive-similar-heading">Similar works available for sale</h2>
          <div className="piece-grid piece-grid--three">
            {similarPieces.map((similarPiece) => {
              const cents = similarPiece.sale_price_cents ?? similarPiece.price_cents;
              const price = cents == null ? null : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
              return (
                <button className="piece-card" type="button" onClick={() => navigate(`/pieces/${similarPiece.id}-${similarPiece.slug}`)} key={similarPiece.id}>
                  <span className="piece-card__image-wrap">
                    {similarPiece.image?.url ? <img className="piece-card__image" src={similarPiece.image.url} alt={similarPiece.image.alt || similarPiece.title} loading="lazy" /> : <span className="piece-card__image piece-card__image--empty" />}
                  </span>
                  <span className="piece-card__details">
                    <span className="piece-card__title" role="heading" aria-level="3">{similarPiece.title}</span>
                    <span className="piece-card__meta">{similarPiece.size}{similarPiece.medium ? ` · ${similarPiece.medium}` : ''}</span>
                    {price && <strong>{price}</strong>}
                  </span>
                </button>
              );
            })}
          </div>
        </section>
      )}
    </article>
  );
};

export default PiecePage;
