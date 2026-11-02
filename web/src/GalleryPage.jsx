import { useEffect, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import KenBurnsSlideshow from './KenBurnsSlideshow.jsx';

const categories = [
  { slug: 'pets-animals', name: 'ANIMALS' },
  { slug: 'space-nature', name: 'NATURE & SPACE' },
  { slug: 'people', name: 'PEOPLE & FIGURES' },
  { slug: 'fantasy', name: 'FANTASY' },
  { slug: 'land-cityscapes', name: 'LAND & CITYSCAPES' },
  { slug: 'discounted', name: 'DISCOUNTED PIECES', discounted: true },
];

const currency = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
const DISCOUNTED_PRICE_LIMIT_CENTS = 10_000;
const BANNER_PAGE_SIZE = 50;
const BANNER_IMAGE_COUNT = 10;
const CATEGORY_BANNER_IMAGE_COUNT = 5;
const GALLERY_BANNER_CONFIG = {
  intervalMs: 5630,
  fadeMs: 1800,
  aspectRatio: '16 / 7',
  effects: [
    'classic-in-bottom-left-center',
    'pan-left-right',
    'zoom-out-center',
    'classic-in-top-right',
  ],
  frameEffect: 'fade',
};

const canvasArea = (size) => {
  const dimensions = String(size || '').match(/\d+(?:\.\d+)?/g);
  return dimensions?.length >= 2 ? Number(dimensions[0]) * Number(dimensions[1]) : 0;
};

const fetchCatalogPages = async (filters, signal) => {
  const fetchPage = async (offset) => {
    const query = new URLSearchParams({ ...filters, sort: 'added', limit: String(BANNER_PAGE_SIZE), offset: String(offset) });
    const response = await fetch(`/api/pieces?${query}`, { signal });
    if (!response.ok) throw new Error('Catalog request failed');
    return response.json();
  };

  const firstPage = await fetchPage(0);
  const total = Number(firstPage.meta?.total) || firstPage.data.length;
  const otherPages = await Promise.all(
    Array.from({ length: Math.ceil(total / BANNER_PAGE_SIZE) - 1 }, (_, index) => fetchPage((index + 1) * BANNER_PAGE_SIZE)),
  );

  return [firstPage, ...otherPages].flatMap((page) => page.data || []);
};

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
  const [bannerImages, setBannerImages] = useState([]);
  const [categoryBannerImages, setCategoryBannerImages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    if (categorySlug) return undefined;

    const controller = new AbortController();
    const loadBannerImages = async () => {
      const fetchPage = async (offset) => {
        const query = new URLSearchParams({
          status: 'available',
          origin: 'original',
          sort: 'added',
          limit: String(BANNER_PAGE_SIZE),
          offset: String(offset),
        });
        const response = await fetch(`/api/pieces?${query}`, { signal: controller.signal });
        if (!response.ok) throw new Error('Catalog request failed');
        return response.json();
      };

      try {
        const firstPage = await fetchPage(0);
        const total = Number(firstPage.meta?.total) || firstPage.data.length;
        const offsets = Array.from(
          { length: Math.ceil(total / BANNER_PAGE_SIZE) - 1 },
          (_, index) => (index + 1) * BANNER_PAGE_SIZE,
        );
        const remainingPages = await Promise.all(offsets.map(fetchPage));
        if (controller.signal.aborted) return;

        const pieces = [firstPage, ...remainingPages].flatMap((page) => page.data || []);
        const addedOrder = new Map(pieces.map((piece, index) => [piece.id, index]));
        const largestPieces = pieces
          .filter((piece) => piece.image?.url && canvasArea(piece.size) > 0)
          .sort((a, b) => canvasArea(b.size) - canvasArea(a.size) || addedOrder.get(a.id) - addedOrder.get(b.id))
          .slice(0, BANNER_IMAGE_COUNT)
          .sort((a, b) => addedOrder.get(a.id) - addedOrder.get(b.id));

        setBannerImages(largestPieces.map((piece) => ({
          id: piece.id,
          src: piece.image.url,
          alt: piece.image.alt || piece.title,
        })));
      } catch (reason) {
        if (reason.name !== 'AbortError') setBannerImages([]);
      }
    };

    loadBannerImages();
    return () => controller.abort();
  }, [categorySlug]);

  useEffect(() => {
    if (!category) return undefined;

    const controller = new AbortController();
    const loadCategoryBanner = async () => {
      try {
        const filters = {
          status: category.discounted ? 'available,sold' : 'available,sold,in_studio,on_display',
          origin: 'original',
        };
        if (category.discounted) filters.discounted = '1';
        else filters.category = category.slug;

        const pieces = await fetchCatalogPages(filters, controller.signal);
        if (controller.signal.aborted) return;

        const addedOrder = new Map(pieces.map((piece, index) => [piece.id, index]));
        const candidates = pieces.filter((piece) => {
          if (!piece.image?.url || canvasArea(piece.size) <= 0) return false;
          if (!category.discounted) return true;
          const listedPrice = piece.status === 'available'
            ? (piece.sale_price_cents ?? piece.price_cents)
            : piece.listed_price_cents;
          return listedPrice > 0 && listedPrice <= DISCOUNTED_PRICE_LIMIT_CENTS;
        });
        const largestFirst = (a, b) => canvasArea(b.size) - canvasArea(a.size) || addedOrder.get(a.id) - addedOrder.get(b.id);
        const available = candidates.filter((piece) => piece.status === 'available').sort(largestFirst);
        const archived = candidates.filter((piece) => piece.status !== 'available').sort(largestFirst);
        const selected = [
          ...available.slice(0, CATEGORY_BANNER_IMAGE_COUNT),
          ...archived.slice(0, Math.max(0, CATEGORY_BANNER_IMAGE_COUNT - Math.min(available.length, CATEGORY_BANNER_IMAGE_COUNT))),
        ]
          .sort((a, b) => addedOrder.get(a.id) - addedOrder.get(b.id));

        setCategoryBannerImages(selected.map((piece) => ({
          id: piece.id,
          src: piece.image.url,
          alt: piece.image.alt || piece.title,
        })));
      } catch (reason) {
        if (reason.name !== 'AbortError') setCategoryBannerImages([]);
      }
    };

    loadCategoryBanner();
    return () => controller.abort();
  }, [category]);

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
      <section className="container content-page gallery-page">
        <button className="page-nav-button" type="button" onClick={() => navigate('/')}>← Back to Studio Anni</button>
        <p className="eyebrow">Galleries: Original paintings by Anni</p>
        <KenBurnsSlideshow
          {...GALLERY_BANNER_CONFIG}
          images={bannerImages}
          className="gallery-page__banner"
          ariaLabel="Featured paintings from the galleries"
        >
          <h1>GALLERIES</h1>
        </KenBurnsSlideshow>
        <p className="section-lede">Choose a collection gallery to explore available original paintings.</p>
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
    <section className="container content-page gallery-page">
      <button className="page-nav-button" type="button" onClick={() => navigate('/gallery')}>← Main Gallery Selection</button>
      <p className="eyebrow">GALLERIES: Original paintings by Anni</p>
      <KenBurnsSlideshow
        {...GALLERY_BANNER_CONFIG}
        images={categoryBannerImages}
        className="gallery-page__banner"
        ariaLabel={`Featured paintings in ${category.name}`}
      >
        <h1>{category.name}</h1>
      </KenBurnsSlideshow>
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
          <h2 id="past-heading">Archived works from this collection</h2>
          <p className="section-lede">A few past works from the collection in the archive.</p>
          {loading ? <p className="catalog-message">Loading past works…</p> : error ? (
            <p className="catalog-message">Past works are temporarily unavailable. Please try again shortly.</p>
          ) : unavailablePieces.length ? (
            <PieceGrid pieces={unavailablePieces} unavailable />
          ) : <p className="catalog-message">There is no recent past work in this collection.</p>}
        </section>
      )}
    </section>
  );
};

export default GalleryPage;
