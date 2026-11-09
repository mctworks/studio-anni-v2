import { useState } from 'react';
import { Navigate, useSearchParams } from 'react-router-dom';
import AuthFlow from './account/AuthFlow.jsx';
import { isStaff, useAuth } from './AuthContext.jsx';

export default function LoginPage() {
  const { user, setUser } = useAuth();
  const [params] = useSearchParams();
  const [codesLeft, setCodesLeft] = useState(null);

  if (user) {
    return <Navigate to={isStaff(user) ? '/mimosa' : '/account'} replace state={{ codesLeft }} />;
  }

  return (
    <AuthFlow
      startAt={params.has('signup') ? 'register' : 'login'}
      onDone={(u, left) => {
        setCodesLeft(left ?? null);
        setUser(u);
      }}
    />
  );
}
