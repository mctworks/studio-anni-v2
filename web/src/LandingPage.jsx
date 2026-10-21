import { useEffect, useState } from 'react';
import logo from './assets/sa-logo-v2.svg';
import logoDark from './assets/sa-logo-v2-dark-mode.svg';

const LandingPage = () => {
  const [health, setHealth] = useState('checking');
  const [isDarkMode, setIsDarkMode] = useState(false);

  useEffect(() => {
    // 1. API Health
    fetch('/api/health')
      .then((r) => r.json())
      .then((d) => setHealth(d.status))
      .catch(() => setHealth('API unreachable'));

    // 2. Theme Sync
    const updateTheme = () => {
      setIsDarkMode(document.documentElement.classList.contains('dark-mode'));
    };

    updateTheme();

    // Watch for class changes on <html> tag (triggered by ThemeToggle)
    const observer = new MutationObserver(updateTheme);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    return () => observer.disconnect();
  }, []);

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
        {/* TO-DO: HomepagePromo component*/}

        <h3>Anni on Bluesky Social</h3>
        {/* TO-DO: Bluesky component */}
      </div>

      <div className="container homepage">
        <h2>Latest Commissions</h2>
        {/* TO-DO: Display lastest 3 commissions */}
        {/* TO-DO: Display if commissions are being accepted. Green light: Open, Yellow Light: Open, with delays, Red light: Temporarily Closed */}
        <p>
          Want to see your fur babies or a special someone captured on canvas in immaculate detail? Since she expanded her work in capturing animals and individuals in 2018, Anni has been commissioned to capture a vast number of her clients' loved ones, be it a beloved household pet, close friends, family members, and sometimes even the occaisional cat dressed as a human!
        </p>
      </div>

      <div className="container homepage">
        <h2>Works For Purchase</h2>
        <div className="purchase-grid">
          <div>{/* Latest two pieces available for purchase*/}</div>
          <div><p>The galleries include orginal acyllic paintings by Anni. While she is probably best known for her painstakingly detailed paintings of animals, her body of work includes nature, people, space, fantasy and landscapes. Her pieces are available for purchase here at reasonable, but affordable, prices. We normally ship pieces via USPS, though for customers local to the greater Atlanta area, we can arrange for pick up.</p></div>
          <div>{/* The next two pieces available for purcase*/}</div>
        </div>
      </div>

      {/* TO-DO: TOP SECRET COMPONENT - Anni's going to need to make some magic happen before work can begin. Post-launch section. */}

      {/* Hide this section entirely if there aren't any pieces at an outside art gallery). */}
      <div className="container homepage">
        <h2>Outside The Studio</h2>
        <h3>Currently on Display at {/* Gallery Name */}</h3>
        {/* List all pieces at the gallery, with a link to the piece's page on the website.*/}
        <p>{/*Art Gallery's name/address and homepage link, include socials if we have them*/}</p>
      </div>

      <div className="container homepage hp-archive">
        <h2>Past Works</h2>
        {/* TO-DO: Determine key displayed piece(s) */}
        <p>A vast portfolio of Anni's previous work, including pieces remaining in the studio, art that has been used in film, past commissions and sold works. Please note that this is an archive, and that none of the works presented are available to purchase in any form.</p>
        <button>Browse Archive</button>

        <h3>Recent Archive Additions</h3>
        <div className="archive-grid">
          <div>{/* Last four pieces added to the archive. */}</div>
        </div>
      </div>

      <div className="container homepage">
        <h2>NO GENERATIVE AI USED!</h2>
        <p>Anni has never needed the aid of a soulless digital art thief who can't even count fingers to create her work, and she never will!</p>
        <p>This is pledge that all of the art you see on Studio Anni was created by a human hand and mind. Generative AI will never be used in any facet of Anni's creative process. Additionally, she <b>does not consent</b> to having her work included in the training of any AI model.</p>
        <p>For more information about Anni and her work, please see the ABOUT page.</p>
      </div>
    </>
  );
};

export default LandingPage;