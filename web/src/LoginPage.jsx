import { useState } from 'react';
import { Navigate } from 'react-router-dom';
import Login from './mimosa/Login.jsx';
import './mimosa/mimosa.css';
import { isStaff, useAuth } from './AuthContext.jsx';

export default function LoginPage() {
  const { user, setUser } = useAuth();
  const [codesLeft, setCodesLeft] = useState(null);

  if (user) {
    return <Navigate to={isStaff(user) ? '/mimosa' : '/'} replace state={{ codesLeft }} />;
  }

  return (
    <Login
      onLogin={(u, left) => {
        setCodesLeft(left ?? null);
        setUser(u);
      }}
    />
  );
}
