import { useEffect, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';

const categories = [
  { slug: 'pets-animals', name: 'Animals' },
  { slug: 'space-nature', name: 'Nature & Space' },
  { slug: 'people', name: 'People & Figures' },
  { slug: 'fantasy', name: 'Fantasy' },
  { slug: 'land-cityscapes', name: 'Land & Cityscapes' },
  { slug: 'discounted', name: 'Discounted Pieces', discounted: true },
];

const currency = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
const DISCOUNTED_PRICE_LIMIT_CENTS = 10_000;

const PieceGrid = ({ pieces, unavailable = false }) => {
  const navigate = useNavigate();

  return (
    <div className="piece-grid piece-grid--three gallery-page__grid">
      {pieces.map((piece) => {
        const cents = piece.sale_price_cents ?? piece.price_cents;
        const price = cents == null ? (piece.status === 'sold' ? 'Sold' : 'On display') : currency.format(cents / 100);

        return (
          <button className="piece-card" type="button" onClick={() => navigate(`/pieces/${piece.id}-${piece.slug}`)} key={piece.id}>
            <span className="piece-card__image-wrap">
              {piece.image?.url ? <img className="piece-card__image" src={piece.image.url} alt={piece.image.alt || piece.title} loading="lazy" /> : <span className="piece-card__image piece-card__image--empty" />}
            </span>
            <span className="piece-card__details">
              <span className="piece-card__title" role="heading" aria-level="3">{piece.title}</span>
              <span className="piece-card__meta">{piece.size}{piece.medium ? ` · ${piece.medium}` : ''}</span>
              {unavailable && piece.status_note ? <span className="piece-card__meta">{piece.status_note}</span> : null}
              <strong>{price}</strong>
            </span>
          </button>
        );
      })}
    </div>
  );
};

const GalleryPage = () => {
  const { categorySlug } = useParams();
  const navigate = useNavigate();
  const category = categories.find((item) => item.slug === categorySlug);
  const [availablePieces, setAvailablePieces] = useState([]);
  const [unavailablePieces, setUnavailablePieces] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!category) return undefined;

    const controller = new AbortController();
    const availableQuery = new URLSearchParams({ status: 'available', origin: 'original', sort: 'newest', limit: '50' });
    const unavailableQuery = new URLSearchParams({ status: category.discounted ? 'sold' : 'sold,on_display', origin: 'original', sort: 'recently_sold', limit: category.discounted ? '50' : '4' });
    if (category.discounted) {
      availableQuery.set('discounted', '1');
      unavailableQuery.set('discounted', '1');
    } else {
      availableQuery.set('category', category.slug);
      unavailableQuery.set('category', category.slug);
    }

    Promise.all([
      fetch(`/api/pieces?${availableQuery}`, { signal: controller.signal }),
      fetch(`/api/pieces?${unavailableQuery}`, { signal: controller.signal }),
    ])
      .then(async ([availableResponse, unavailableResponse]) => {
        if (!availableResponse.ok || !unavailableResponse.ok) throw new Error('Catalog could not be loaded');
        const [availableResult, unavailableResult] = await Promise.all([availableResponse.json(), unavailableResponse.json()]);
        const available = category.discounted
          ? availableResult.data.filter((piece) => piece.price_cents !== null && piece.price_cents <= DISCOUNTED_PRICE_LIMIT_CENTS)
          : availableResult.data;
        const unavailable = category.discounted
          ? unavailableResult.data.filter((piece) => (piece.listed_price_cents ?? piece.price_cents) !== null && (piece.listed_price_cents ?? piece.price_cents) <= DISCOUNTED_PRICE_LIMIT_CENTS && piece.status === 'sold').slice(0, 4)
          : unavailableResult.data;
        setAvailablePieces(available);
        setUnavailablePieces(unavailable);
      })
      .catch((reason) => {
        if (reason.name !== 'AbortError') setError(true);
      })
      .finally(() => setLoading(false));

    return () => controller.abort();
  }, [category]);

  if (categorySlug && !category) return <Navigate to="/gallery" replace />;

  if (!category) {
    return (
      <section className="container homepage gallery-page">
        <button className="page-nav-button" type="button" onClick={() => navigate('/')}>← Back to Studio Anni</button>
        <p className="eyebrow">Original paintings</p>
        <h1>Browse the Gallery</h1>
        <p className="section-lede">Choose a collection to explore available original paintings.</p>
        <nav className="gallery-page__selectors" aria-label="Gallery categories">
          {categories.map((item) => (
            <button className="gallery-page__selector" type="button" onClick={() => navigate(`/gallery/${item.slug}`)} key={item.slug}>
              <span>{item.name}</span>
              <span aria-hidden="true">→</span>
            </button>
          ))}
        </nav>
      </section>
    );
  }

  return (
    <section className="container homepage gallery-page">
      <button className="page-nav-button" type="button" onClick={() => navigate('/gallery')}>← All gallery categories</button>
      <p className="eyebrow">Original paintings</p>
      <h1>{category.name}</h1>
      <p className="section-lede">{category.discounted ? 'Original paintings listed for $100 or less.' : 'Available original paintings in this collection.'}</p>
      <section aria-labelledby="available-heading">
        <h2 id="available-heading">Available now</h2>
        {loading ? <p className="catalog-message">Loading available works…</p> : error ? (
          <p className="catalog-message">Available works are temporarily unavailable. Please try again shortly.</p>
        ) : availablePieces.length ? (
          <PieceGrid pieces={availablePieces} />
        ) : <p className="catalog-message">There are no works listed for purchase in this collection right now.</p>}
      </section>
      {!category.discounted && (
        <section className="gallery-page__past" aria-labelledby="past-heading">
          <h2 id="past-heading">Recently sold &amp; on display</h2>
          <p className="section-lede">A few originals from the collection that are no longer available for purchase.</p>
          {loading ? <p className="catalog-message">Loading recent past work…</p> : error ? (
            <p className="catalog-message">Recent past work is temporarily unavailable. Please try again shortly.</p>
          ) : unavailablePieces.length ? (
            <PieceGrid pieces={unavailablePieces} unavailable />
          ) : <p className="catalog-message">There is no recent past work in this collection.</p>}
        </section>
      )}
    </section>
  );
};

export default GalleryPage;
