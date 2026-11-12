import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import logo from './assets/sa-logo-v2.svg';
import logoDark from './assets/sa-logo-v2-dark-mode.svg';
import archiveIconDark from './assets/archive-icon-dark-mode.svg';
import archiveIconLight from './assets/archive-icon-light-mode.svg';
import galleryIconDark from './assets/galleries-icon-dark-mode.svg';
import galleryIconLight from './assets/galleries-icon-light-mode.svg';
import { useTheme } from './ThemeContext.jsx';
import BlueskyFeed from './BlueskyFeed.jsx';
import displayLocations from './displayLocations.json';
import { availabilityCopy } from './commissionStatus.js';

const locationImages = import.meta.glob('./assets/images/*', { eager: true, query: '?url', import: 'default' });


const piecePath = (piece) => `/pieces/${piece.id}-${piece.slug}`;

const formatPrice = (piece) => {
  const cents = piece.sale_price_cents ?? piece.price_cents;
  return cents == null
    ? 'Price on request'
    : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
};

const PieceCard = ({ piece, showPrice = false }) => {
  const navigate = useNavigate();

  return (
    <button className="piece-card" type="button" onClick={() => navigate(piecePath(piece))}>
      <span className="piece-card__image-wrap">
        {piece.image?.url ? (
          <img className="piece-card__image" src={piece.image.url} alt={piece.image.alt || piece.title} loading="lazy" />
        ) : (
          <span className="piece-card__image piece-card__image--empty" aria-label="Image coming soon" />
        )}
      </span>
      <span className="piece-card__details">
        <span className="piece-card__title" role="heading" aria-level="3">{piece.title}</span>
        <span className="piece-card__meta">{piece.size}{piece.medium ? ` · ${piece.medium}` : ''}</span>
        {showPrice && <strong>{formatPrice(piece)}</strong>}
      </span>
    </button>
  );
};

const LandingPage = () => {
  const navigate = useNavigate();
  const { isDarkMode } = useTheme();
  const [health, setHealth] = useState('checking');
  const [commissionAvailability, setCommissionAvailability] = useState('unknown');
  const [commissions, setCommissions] = useState([]);
  const [availablePieces, setAvailablePieces] = useState([]);
  const [archivePieces, setArchivePieces] = useState([]);
  const [displayPieces, setDisplayPieces] = useState([]);
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [catalogErrors, setCatalogErrors] = useState({ commissions: false, available: false, archive: false });

  useEffect(() => {
    fetch('/api/health')
      .then((r) => {
        if (!r.ok) throw new Error('Health check failed');
        return r.json();
      })
      .then((d) => setHealth(d.status))
      .catch(() => setHealth('API unreachable'));

    const controller = new AbortController();
    fetch('/api/commissions/info', { signal: controller.signal })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('Commission info failed'))))
      .then((d) => setCommissionAvailability(d.availability))
      .catch(() => {});

    const fetchPieces = async (query) => {
      const response = await fetch(`/api/pieces?${query}`, { signal: controller.signal });
      if (!response.ok) throw new Error('Catalog request failed');
      const result = await response.json();
      return result.data;
    };

    Promise.allSettled([
      fetchPieces('origin=commission&sort=newest&limit=3'),
      fetchPieces('status=available&sort=newest&limit=4'),
      fetchPieces('status=sold,in_studio&origin=original&sort=recently_sold&limit=4'),
      fetchPieces('status=on_display&sort=newest&limit=50'),
    ])
      .then((results) => {
        if (controller.signal.aborted) return;
        const [latestCommissions, latestAvailable, latestArchive, currentlyDisplayed] = results;
        if (latestCommissions.status === 'fulfilled') setCommissions(latestCommissions.value);
        if (latestAvailable.status === 'fulfilled') setAvailablePieces(latestAvailable.value);
        if (latestArchive.status === 'fulfilled') setArchivePieces(latestArchive.value);
        if (currentlyDisplayed.status === 'fulfilled') setDisplayPieces(currentlyDisplayed.value);
        setCatalogErrors({
          commissions: latestCommissions.status === 'rejected',
          available: latestAvailable.status === 'rejected',
          archive: latestArchive.status === 'rejected',
        });
      })
      .finally(() => setCatalogLoading(false));

    return () => {
      controller.abort();
    };
  }, []);

  const availability = availabilityCopy[commissionAvailability] || availabilityCopy.unknown;
  const venues = [...new Set(displayPieces.map((piece) => piece.status_note).filter(Boolean))];

  return (
    <>
      <div className="container under-construction">
        <div className="logo-container">
          <img
            src={isDarkMode ? logoDark : logo}
            width="70%"
            alt="Studio Anni Logo"
            className="logo"
          />
        </div>
        <h1>Welcome to Studio Anni Ver. 2.0</h1>
        <p>Construction is UNDERWAY! WATCH YOUR HEAD! A Div or a Nav bar might fall...</p>
        <p>
          API Health: <span style={{ color: health === 'ok' ? '#00cc0a' : 'red' }}>{health}</span>
        </p>
        <p>
          Thank you for visiting Studio Anni, the home of Atlanta-based artist Anni Thompson, and her extensive catalog of detailed paintings. We hope you stay awhile and take in Anni's breathtaking captures on canvas.
        </p>
      </div>

      <div className="container homepage">
        <h2>Latest From Anni</h2>
        {/* TO-DO: Promo component*/}

        <div className="bluesky-section">
          <BlueskyFeed />
        </div>
      </div>

      <div className="container homepage">
        <h2>Commissions</h2>
        <div className={`commission-status commission-status--${availability.tone}`}>
          <span className="commission-status__dot" aria-hidden="true" />
          <span>{availability.label}</span>
        </div>
        <p>
          Want to see your fur babies or a special someone captured on canvas in immaculate detail? Since she expanded her work in capturing animals and individuals in 2018, Anni has been commissioned to capture a vast number of her clients' loved ones, be it a beloved household pet, close friends, family members, and sometimes even the occaisional cat dressed as a human!
        </p>
        <p><button type="button" className="page-nav-button" onClick={() => navigate('/commissions')}>Prices &amp; how to commission →</button></p>
        <h3>Recent Commissions</h3>
        {catalogLoading ? <p className="catalog-message">Loading recent commissions…</p> : commissions.length > 0 ? (
          <div className="piece-grid piece-grid--three">
            {commissions.map((piece) => <PieceCard key={piece.id} piece={piece} />)}
          </div>
        ) : (
          <p className="catalog-message">{catalogErrors.commissions ? 'Recent commissions are temporarily unavailable.' : 'Recent commissions will appear here.'}</p>
        )}
      </div>

      <div className="container homepage">
        <h2>Works For Purchase</h2>
        <div className="purchase-grid">
          <div className="piece-grid piece-grid--stacked">
            {availablePieces.slice(0, 2).map((piece) => <PieceCard key={piece.id} piece={piece} showPrice />)}
          </div>
          <div className="purchase-intro">
            <p>The collection galleries include original acrylic paintings by Anni. While she is probably best known for her painstakingly detailed paintings of animals, her body of work includes nature, people, space, fantasy and landscapes. Her pieces are available for purchase here at reasonable prices. We normally ship pieces via USPS, though for customers local to the greater Atlanta area, we can arrange for pick up.</p>
            <button className="page-nav-button" type="button" onClick={() => navigate('/gallery')}>
              <img src={isDarkMode ? galleryIconDark : galleryIconLight}
                alt="Galleries"
                width="24"
                height="24"
              />
              Explore the galleries <span aria-hidden="true">→</span></button>
          </div>
          <div className="piece-grid piece-grid--stacked">
            {availablePieces.slice(2, 4).map((piece) => <PieceCard key={piece.id} piece={piece} showPrice />)}
          </div>
        </div>
        {!catalogLoading && availablePieces.length === 0 && (
          <p className="catalog-message">{catalogErrors.available ? 'Works for purchase are temporarily unavailable.' : 'There are no works listed for purchase right now.'}</p>
        )}
      </div>

      {/* TO-DO: TOP SECRET COMPONENT - Anni's going to need to make some magic happen before work can begin. Post-launch section. */}

      {displayPieces.length > 0 && (
        <div className="container homepage">
          <h2>Outside The Studio</h2>
          <p className="section-lede">A selection of Anni's work is currently on display in person.</p>
          {venues.map((venue) => {
            const location = displayLocations.find((item) => item.name.toLocaleLowerCase() === venue.trim().toLocaleLowerCase());
            const image = locationImages[`./assets/images/${location?.image}`];

            return (
              <section className="outside-venue" key={venue}>
                <h3>Currently on Display at {venue}</h3>
                <div className="piece-grid piece-grid--three">
                  {displayPieces.filter((piece) => piece.status_note === venue).map((piece) => <PieceCard key={piece.id} piece={piece} />)}
                </div>
                {location && (
                  <div className="outside-venue__details">
                    {image && <img className="outside-venue__image" src={image} alt={location.imageAlt} loading="lazy" />}
                    <div className="outside-venue__contact">
                      <p><a href={`https://maps.google.com/?q=${encodeURIComponent(location.address)}`} target="_blank" rel="noreferrer">{location.address}</a></p>
                      <p>{location.hours}</p>
                      <a href={location.website} target="_blank" rel="noreferrer">Visit {location.name} website <span aria-hidden="true">↗</span></a>
                    </div>
                  </div>
                )}
              </section>
            );
          })}
          {displayPieces.some((piece) => !piece.status_note) && (
            <section className="outside-venue">
              <h3>Currently on Display</h3>
              <div className="piece-grid piece-grid--three">
                {displayPieces.filter((piece) => !piece.status_note).map((piece) => <PieceCard key={piece.id} piece={piece} />)}
              </div>
            </section>
          )}
        </div>
      )}

      <div className="container homepage">
        <h2>Past Works</h2>
        <div className="purchase-grid">
          <div className="piece-grid piece-grid--stacked">
            {archivePieces.slice(0, 2).map((piece) => <PieceCard key={piece.id} piece={piece} />)}
          </div>
          <div className="purchase-intro">
            <p>A selection of Anni's past work, including sold paintings and pieces remaining in the studio. These archived works are not available to purchase.</p>
            <button className="page-nav-button" type="button" onClick={() => navigate('/archive')}>
              <img src={isDarkMode ? archiveIconDark : archiveIconLight}
                alt="Archive"
                width="24"
                height="24"
              />
              Explore the archive <span aria-hidden="true">→</span>
            </button>
          </div>
          <div className="piece-grid piece-grid--stacked">
            {archivePieces.slice(2, 4).map((piece) => <PieceCard key={piece.id} piece={piece} />)}
          </div>
        </div>
        {!catalogLoading && archivePieces.length === 0 && (
          <p className="catalog-message">{catalogErrors.archive ? 'Past works are temporarily unavailable.' : 'There are no archived works to show right now.'}</p>
        )}
      </div>

      <div className="container homepage">
        <h2>NO GENERATIVE AI!</h2>
        <p>Anni has never needed the aid of a soulless digital art thief who can't even count fingers to create her work, and she never will!</p>
        <p>This is pledge that all of the art you see on Studio Anni was created by a human hand and mind. Generative AI will never be used in any facet of Anni's creative process. Additionally, she <b>does not consent</b> to having her work included in the training of any AI model.</p>
        <p>For more information about Anni and her work, please see the ABOUT page.</p>
      </div>
    </>
  );
};

export default LandingPage;