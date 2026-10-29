import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

const PiecePage = () => {
  const navigate = useNavigate();
  const { pieceKey } = useParams();
  const pieceId = pieceKey.split('-')[0];
  const [piece, setPiece] = useState(null);
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

  if (loading) {
    return <section className="container homepage piece-page"><p className="catalog-message">Loading artwork…</p></section>;
  }

  if (error || !piece) {
    return (
      <section className="container homepage piece-page">
        <h1>Artwork not found</h1>
        <p>This piece may no longer be available.</p>
        <button className="page-nav-button" type="button" onClick={() => navigate('/')}>Return to the homepage <span aria-hidden="true">→</span></button>
      </section>
    );
  }

  const price = piece.sale_price_cents ?? piece.price_cents;
  const formattedPrice = price == null ? null : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price / 100);

  return (
    <article className="container homepage piece-page">
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
          {piece.images?.length > 1 && (
            <div className="piece-detail__angles" aria-label="Additional views">
              {piece.images.slice(1).map((image) => <img key={image.url} src={image.url} alt={image.alt || `${piece.title}, additional view`} loading="lazy" />)}
            </div>
          )}
        </div>
      </div>
    </article>
  );
};

export default PiecePage;
