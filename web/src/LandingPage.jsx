import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import logo from './assets/sa-logo-v2.svg';
import logoDark from './assets/sa-logo-v2-dark-mode.svg';
import archiveIconDark from './assets/archive-icon-dark-mode.svg';
import archiveIconLight from './assets/archive-icon-light-mode.svg';
import galleryIconDark from './assets/galleries-icon-dark-mode.svg';
import galleryIconLight from './assets/galleries-icon-light-mode.svg';
import commissionsIconDark from './assets/commission-icon-dark-mode.svg';
import commissionsIconLight from './assets/commission-icon-light-mode.svg';
import guestbookIconDark from './assets/guestbook-icon-dark-mode.svg';
import guestbookIconLight from './assets/guestbook-icon-light-mode.svg';
import { useTheme } from './ThemeContext.jsx';
import BlueskyFeed from './BlueskyFeed.jsx';
import displayLocations from './displayLocations.json';
import { availabilityCopy } from './commissionStatus.js';
import Promo from './Promo.jsx';
import KenBurnsSlideshow from './KenBurnsSlideshow.jsx';

const locationImages = import.meta.glob('./assets/images/*', { eager: true, query: '?url', import: 'default' });
const HERO_PIECE_KEYS = [
  '12-maiko-1',
  '22-dobby',
  '150-fin-del-mundo',
  '131-silver',
  '69-najla',
  '85-octopus-lord-of-time-space',
  '209-serene-dusk',
  '219-wood-nymph-iii',
];


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

const ThemeSwapIcon = ({ darkSrc, lightSrc, alt }) => (
  <span className="landing-nav-button__icon" aria-hidden="true">
    <img className="landing-nav-button__icon-dark" src={darkSrc} alt="" width="24" height="24" />
    <img className="landing-nav-button__icon-light" src={lightSrc} alt="" width="24" height="24" />
  </span>
);

const LandingPage = () => {
  const navigate = useNavigate();
  const { isDarkMode } = useTheme();
  const [commissionAvailability, setCommissionAvailability] = useState('unknown');
  const [commissions, setCommissions] = useState([]);
  const [availablePieces, setAvailablePieces] = useState([]);
  const [archivePieces, setArchivePieces] = useState([]);
  const [displayPieces, setDisplayPieces] = useState([]);
  const [heroSlides, setHeroSlides] = useState([]);
  const [promo, setPromo] = useState(null);
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [catalogErrors, setCatalogErrors] = useState({ commissions: false, available: false, archive: false });

  useEffect(() => {
    const controller = new AbortController();
    fetch('/api/commissions/info', { signal: controller.signal })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('Commission info failed'))))
      .then((d) => setCommissionAvailability(d.availability))
      .catch(() => {});

    fetch('/api/promo', { signal: controller.signal })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('Promo request failed'))))
      .then((d) => setPromo(d.promo))
      .catch(() => {});

    Promise.all(HERO_PIECE_KEYS.map(async (pieceKey) => {
      try {
        const response = await fetch(`/api/pieces/${encodeURIComponent(pieceKey)}`, { signal: controller.signal });
        if (!response.ok) return null;
        const result = await response.json();
        const piece = result.data;
        return piece?.image?.url
          ? { id: piece.id, src: piece.image.url, alt: piece.image.alt || piece.title }
          : null;
      } catch {
        return null;
      }
    })).then((slides) => {
      if (!controller.signal.aborted) setHeroSlides(slides.filter(Boolean));
    });

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
      <h1 className="landing-hero__visually-hidden">Studio Anni</h1>
      <KenBurnsSlideshow
        images={heroSlides}
        intervalMs={1630}
        effects="zoom-out-center"
        frameEffect="none"
        aspectRatio="16 / 7"
        className={`landing-hero ${isDarkMode ? 'landing-hero--dark' : 'landing-hero--light'}`}
        ariaLabel="Featured paintings by Anni"
      >
        <div className="landing-hero__content">
          <img
            src={isDarkMode ? logoDark : logo}
            alt="Studio Anni, Est. 2017"
            className="landing-hero__logo"
          />
        </div>
      </KenBurnsSlideshow>

      <div className="container homepage">
        <h2>Latest From Anni</h2>
        <Promo image={promo?.image_url} imageAlt={promo?.image_alt} heading={promo?.heading} text={promo?.text} />

        <div className="bluesky-section">
          <BlueskyFeed />
        </div>
      </div>

      <div className="container homepage commissions-section">
        <h2>Commissions</h2>
        <div className={`commission-status commission-status--${availability.tone}`}>
          <span className="commission-status__dot" aria-hidden="true" />
          <span>{availability.label}</span>
        </div>
        <p>
          Want to see your fur babies or a special someone captured on canvas in immaculate detail? Since she expanded her work in capturing animals and individuals in 2018, Anni has been commissioned to capture a vast number of her clients' loved ones, be it a beloved household pet, close friends, family members, and sometimes even the occaisional cat dressed as a human!
        </p>
        <p><button type="button" className="page-nav-button landing-nav-button" onClick={() => navigate('/commissions')}><ThemeSwapIcon darkSrc={commissionsIconDark} lightSrc={commissionsIconLight} /> Learn about Commissions →</button></p>
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
            <button className="page-nav-button landing-nav-button" type="button" onClick={() => navigate('/gallery')}>
              <ThemeSwapIcon darkSrc={galleryIconDark} lightSrc={galleryIconLight} />
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
            <button className="page-nav-button landing-nav-button" type="button" onClick={() => navigate('/archive')}>
              <ThemeSwapIcon darkSrc={archiveIconDark} lightSrc={archiveIconLight} />
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
        <h2>No Generative AI</h2>
        <p>Anni has never needed the aid of a soulless digital art thief who can't even count fingers to create her work, and she never will!</p>
        <p>This is pledge that all of the art you see on Studio Anni was created by a human hand and mind. Generative AI will never be used in any facet of Anni's creative process. Additionally, she <b>does not consent</b> to having her work included in the training of any AI model.</p>
        <p>For more information about Anni and her work, please see her <Link to="/about">About</Link> page.</p>
      </div>

      <div className="container homepage commissions-section">
        <h2>Guestbook</h2>
        <img className="landing-guestbook__image" src={isDarkMode ? guestbookIconDark : guestbookIconLight} alt="" width="100" height="100" />
        <p>Whether this is your first visit, or you've commissioned a few pet portraits in the past, we'd love to hear from you! Feel free to leave us a message in our Guestbook.</p>
        <button className="page-nav-button landing-nav-button" type="button" onClick={() => navigate('/guestbook')}>
              <ThemeSwapIcon darkSrc={guestbookIconDark} lightSrc={guestbookIconLight} />
              View the Guestbook <span aria-hidden="true">→</span>
            </button>
      </div>
      <br />
    </>
  );
};

export default LandingPage;