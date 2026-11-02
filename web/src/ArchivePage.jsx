import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';

const categories = [
  { slug: 'pets-animals', name: 'Animals' },
  { slug: 'space-nature', name: 'Nature & Space' },
  { slug: 'people', name: 'People & Figures' },
  { slug: 'fantasy', name: 'Fantasy' },
  { slug: 'land-cityscapes', name: 'Land & Cityscapes' },
];

const PAGE_SIZE = 15;
const statusLabel = { sold: 'Sold', in_studio: 'In studio', on_display: 'On display' };

const ArchivePage = () => {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const query = searchParams.get('q') ?? '';
  const categoryParam = searchParams.get('category') ?? '';
  const category = categories.find((item) => item.slug === categoryParam)?.slug ?? '';
  const page = Math.max(1, Number.parseInt(searchParams.get('page') ?? '1', 10) || 1);
  const [searchInput, setSearchInput] = useState(query);
  const [pieces, setPieces] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const pageCount = Math.max(1, Math.ceil(total / PAGE_SIZE));

  useEffect(() => setSearchInput(query), [query]);

  useEffect(() => {
    const controller = new AbortController();
    const params = new URLSearchParams({
      status: 'sold,in_studio,on_display',
      sort: 'recently_sold',
      limit: String(PAGE_SIZE),
      offset: String((page - 1) * PAGE_SIZE),
    });
    if (query) params.set('q', query);
    if (category) params.set('category', category);

    setLoading(true);
    setError(false);
    fetch(`/api/pieces?${params}`, { signal: controller.signal })
      .then((response) => {
        if (!response.ok) throw new Error('Archive could not be loaded');
        return response.json();
      })
      .then((result) => {
        setPieces(result.data);
        setTotal(result.meta.total);
      })
      .catch((reason) => {
        if (reason.name !== 'AbortError') setError(true);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [query, category, page]);

  useEffect(() => {
    if (!loading && total > 0 && page > pageCount) {
      const params = new URLSearchParams(searchParams);
      params.set('page', String(pageCount));
      setSearchParams(params, { replace: true });
    }
  }, [loading, total, page, pageCount, searchParams, setSearchParams]);

  const submitSearch = (event) => {
    event.preventDefault();
    const params = new URLSearchParams(searchParams);
    const term = searchInput.trim();
    if (term) params.set('q', term);
    else params.delete('q');
    params.delete('page');
    setSearchParams(params);
  };

  const changeCategory = (event) => {
    const params = new URLSearchParams(searchParams);
    if (event.target.value) params.set('category', event.target.value);
    else params.delete('category');
    params.delete('page');
    setSearchParams(params);
  };

  const changePage = (nextPage) => {
    const params = new URLSearchParams(searchParams);
    if (nextPage > 1) params.set('page', String(nextPage));
    else params.delete('page');
    setSearchParams(params);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  return (
    <section className="container content-page archive-page">
      <button className="page-nav-button" type="button" onClick={() => navigate('/')}>← Back to Studio Anni</button>
      <p className="eyebrow">Studio Anni collection</p>
      <h1>Past Works</h1>
      <p className="section-lede">Browse paintings that have sold, are in the studio, or are currently on display. Archived works are not available for purchase.</p>

      <div className="archive-page__filters">
        <form className="archive-page__search" onSubmit={submitSearch} role="search">
          <label htmlFor="archive-search">Search the archive</label>
          <div className="archive-page__search-row">
            <input
              id="archive-search"
              type="search"
              value={searchInput}
              maxLength={100}
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Title, description, or medium"
            />
            <button type="submit" className="page-nav-button">Search</button>
          </div>
        </form>
        <label className="archive-page__category" htmlFor="archive-category">
          Gallery category
          <select id="archive-category" value={category} onChange={changeCategory}>
            <option value="">All categories</option>
            {categories.map((item) => <option value={item.slug} key={item.slug}>{item.name}</option>)}
          </select>
        </label>
      </div>

      {loading ? <p className="catalog-message">Loading the archive…</p> : error ? (
        <p className="catalog-message" role="alert">The archive is temporarily unavailable. Please try again shortly.</p>
      ) : pieces.length ? (
        <>
          <p className="archive-page__count" aria-live="polite">Showing {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, total)} of {total} works</p>
          <div className="piece-grid piece-grid--three archive-page__grid">
            {pieces.map((piece) => (
              <button className="piece-card" type="button" onClick={() => navigate(`/pieces/${piece.id}-${piece.slug}`)} key={piece.id}>
                <span className="piece-card__image-wrap">
                  {piece.image?.url ? <img className="piece-card__image" src={piece.image.url} alt={piece.image.alt || piece.title} loading="lazy" /> : <span className="piece-card__image piece-card__image--empty" />}
                </span>
                <span className="piece-card__details">
                  <span className="piece-card__title" role="heading" aria-level="2">{piece.title}</span>
                  <span className="piece-card__meta">{piece.size}{piece.medium ? ` · ${piece.medium}` : ''}</span>
                  <span className="piece-card__meta">{statusLabel[piece.status] || 'Past work'}</span>
                </span>
              </button>
            ))}
          </div>
          <nav className="archive-page__pagination" aria-label="Archive pages">
            <button className="page-nav-button" type="button" onClick={() => changePage(page - 1)} disabled={page <= 1}>← Previous</button>
            <span aria-current="page">Page {page} of {pageCount}</span>
            <button className="page-nav-button" type="button" onClick={() => changePage(page + 1)} disabled={page >= pageCount}>Next →</button>
          </nav>
        </>
      ) : (
        <p className="catalog-message">No archived works match those filters.</p>
      )}
    </section>
  );
};

export default ArchivePage;
