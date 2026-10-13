import { useEffect, useState } from 'react';
import logo from './assets/sa-logo-v2.svg';

const LandingPage = () => {
  const [health, setHealth] = useState('checking...');

  useEffect(() => {
    fetch('/api/health')
      .then((r) => r.json())
      .then((d) => setHealth(d.status))
      .catch(() => setHealth('API unreachable'));
  }, []);

  return (
    <div className="container under-construction">
      <img src={logo} width="350" alt="Studio Anni Logo" className="logo" />
      <h1>Studio Anni Ver. 2.0</h1>
      <p>Construction is UNDERWAY!</p>
      <p>API Health: <span style={{ color: health === 'ok' ? 'green' : 'red' }}>{health}</span></p>
    </div>
  );
};

export default LandingPage;