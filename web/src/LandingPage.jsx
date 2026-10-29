import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import logo from './assets/sa-logo-v2.svg';
import logoDark from './assets/sa-logo-v2-dark-mode.svg';
import galleryIcon from './assets/galleries-icon-light-mode.svg';
import galleryIconDark from './assets/galleries-icon-dark-mode.svg';
import BlueskyFeed from './BlueskyFeed.jsx';

const commissionAvailability = 'unknown';

const availabilityCopy = {
  open: { label: 'Accepting commissions', tone: 'open' },
  delays: { label: 'Accepting commissions · current delays', tone: 'delays' },
  closed: { label: 'Commissions temporarily closed', tone: 'closed' },
  unknown: { label: 'Commission availability to be announced', tone: 'unknown' },
};

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
  const [health, setHealth] = useState('checking');
  const [isDarkMode, setIsDarkMode] = useState(false);
  const [commissions, setCommissions] = useState([]);
  const [availablePieces, setAvailablePieces] = useState([]);
  const [displayPieces, setDisplayPieces] = useState([]);
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [catalogErrors, setCatalogErrors] = useState({ commissions: false, available: false });

  useEffect(() => {
    fetch('/api/health')
      .then((r) => {
        if (!r.ok) throw new Error('Health check failed');
        return r.json();
      })
      .then((d) => setHealth(d.status))
      .catch(() => setHealth('API unreachable'));

    const updateTheme = () => {
      setIsDarkMode(document.documentElement.classList.contains('dark-mode'));
    };

    updateTheme();

    // Watch for class changes on <html> tag (triggered by ThemeToggle)
    const observer = new MutationObserver(updateTheme);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    const controller = new AbortController();
    const fetchPieces = async (query) => {
      const response = await fetch(`/api/pieces?${query}`, { signal: controller.signal });
      if (!response.ok) throw new Error('Catalog request failed');
      const result = await response.json();
      return result.data;
    };

    Promise.allSettled([
      fetchPieces('origin=commission&sort=newest&limit=3'),
      fetchPieces('status=available&sort=newest&limit=4'),
      fetchPieces('status=on_display&sort=newest&limit=50'),
    ])
      .then((results) => {
        if (controller.signal.aborted) return;
        const [latestCommissions, latestAvailable, currentlyDisplayed] = results;
        if (latestCommissions.status === 'fulfilled') setCommissions(latestCommissions.value);
        if (latestAvailable.status === 'fulfilled') setAvailablePieces(latestAvailable.value);
        if (currentlyDisplayed.status === 'fulfilled') setDisplayPieces(currentlyDisplayed.value);
        setCatalogErrors({
          commissions: latestCommissions.status === 'rejected',
          available: latestAvailable.status === 'rejected',
        });
      })
      .finally(() => setCatalogLoading(false));

    return () => {
      observer.disconnect();
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

        <h3>Anni on Bluesky Social</h3>
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
              <img src={galleryIconDark}
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
          {venues.map((venue) => (
            <section className="outside-venue" key={venue}>
              <h3>Currently on Display at {venue}</h3>
              <div className="piece-grid piece-grid--three">
                {displayPieces.filter((piece) => piece.status_note === venue).map((piece) => <PieceCard key={piece.id} piece={piece} />)}
              </div>
            </section>
          ))}
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
        {/*TO-DO: Choose key artwork(s) for this section */}
        <p className="section-lede">A vast portfolio of Anni's previous work, including pieces remaining in the studio, art that has been used in film, past commissions and sold works. Please note that this is an archive, and that none of the works presented are available to purchase in any form.</p>
        <div className="piece-grid piece-grid--three">
          {/*TO-DO: Display the four most recent additions to the archive */}
        </div>
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