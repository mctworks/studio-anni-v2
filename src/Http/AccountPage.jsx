import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { account } from './api.js';
import AuthFlow from './AuthFlow.jsx';
import Avatar from './Avatar.jsx';
import { useAuth } from '../AuthContext.jsx';
import './account.css';

const messages = {
  taken: 'Already taken.',
  invalid: 'Not valid.',
  too_long: 'Too long.',
};

const avatarMessages = {
  too_large: 'That image is too large (5 MB maximum).',
  unsupported_type: 'Use a JPEG or PNG image.',
  dimensions_too_large: 'That image is too large (dimensions).',
  too_many_attempts: 'Too many uploads. Try again later.',
};

const AvatarEditor = ({ profile, onChange }) => {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const pick = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) return setError(avatarMessages.too_large);
    setError('');
    setBusy(true);
    const r = await account.uploadAvatar(file);
    setBusy(false);
    if (r.ok) onChange(r.data.profile);
    else setError(avatarMessages[r.data?.error] ?? 'Could not save that image.');
  };

  const remove = async () => {
    setBusy(true);
    const r = await account.removeAvatar();
    setBusy(false);
    if (r.ok) onChange(r.data.profile);
  };

  return (
    <div className="account-avatar-row">
      <Avatar src={profile.avatar} name={profile.displayName} />
      <div className="account-avatar-actions">
        <label className="account-link">
          {profile.avatar ? 'Change picture' : 'Add a picture'}
          <input type="file" accept="image/jpeg,image/png" onChange={pick} disabled={busy} hidden />
        </label>
        {profile.avatar && <button className="account-link" type="button" onClick={remove} disabled={busy}>Remove picture</button>}
        {error && <small className="account-error" role="alert">{error}</small>}
      </div>
    </div>
  );
};

const Field = ({ label, error, children }) => (
  <label className="account-field">
    <span>{label}</span>
    {children}
    {error && <small className="account-error">{messages[error] ?? 'Not valid.'}</small>}
  </label>
);

const Settings = ({ profile, onChange, onSignOut }) => {
  const [errors, setErrors] = useState({});
  const [saved, setSaved] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const r = await account.updateProfile({
      displayName: f.get('displayName'),
      location: f.get('location'),
      profilePublic: f.get('profilePublic') === 'on',
    });
    if (r.status === 422) return setErrors(r.data.fields || {});
    setErrors({});
    if (r.ok) {
      onChange(r.data.profile);
      setSaved(true);
    }
  };

  return (
    <>
    <AvatarEditor profile={profile} onChange={onChange} />
    <form onSubmit={submit} className="account-form" onChange={() => setSaved(false)}>
      <h2>{profile.displayName}</h2>
      <p>@{profile.username} · {profile.title}</p>
      <Field label="Display name" error={errors.displayName}>
        <input name="displayName" defaultValue={profile.displayName} maxLength={80} required />
      </Field>
      <Field label="Location (optional)" error={errors.location}>
        <input name="location" defaultValue={profile.location || ''} maxLength={80} />
      </Field>
      <label className="account-check">
        <input name="profilePublic" type="checkbox" defaultChecked={profile.profilePublic} /> Make my profile public
      </label>
      <p className="account-note">Public profiles show your display name, title, location and pieces you own. Your email is never shown.</p>
      {saved && <p role="status">Saved.</p>}
      <button className="page-nav-button" type="submit">Save</button>
      {profile.profilePublic && <p><Link to={`/u/${profile.username}`}>View my public profile</Link></p>}
      <p><button className="account-link" type="button" onClick={onSignOut}>Sign out</button></p>
    </form>
    </>
  );
};

export default function AccountPage() {
  const auth = useAuth();
  const [params] = useSearchParams();
  const [phase, setPhase] = useState('loading');
  const [profile, setProfile] = useState(null);

  const load = () =>
    account.profile().then((r) => {
      if (r.ok) {
        setProfile(r.data.profile);
        setPhase('ready');
      } else {
        setPhase('login');
      }
    });

  useEffect(() => {
    load();
  }, []);

  if (auth.staff) {
    return (
      <div className="container account-page">
        <p>You are signed in as {auth.user.displayName ?? auth.user.email} ({auth.user.role}).</p>
        <p><Link to="/mimosa">Open MIMOSA</Link></p>
        <p><button className="account-link" type="button" onClick={auth.logout}>Sign out</button></p>
      </div>
    );
  }

  if (phase === 'loading') return <div className="container account-page"><p>Loading…</p></div>;

  if (phase === 'login') {
    return (
      <AuthFlow
        startAt={params.has('signup') ? 'register' : 'login'}
        onDone={(u) => {
          auth.setUser(u);
          if (u.role === 'customer') load();
        }}
      />
    );
  }

  return (
    <div className="container account-page">
      <Settings
        profile={profile}
        onChange={setProfile}
        onSignOut={async () => {
          await auth.logout();
          setProfile(null);
          setPhase('login');
        }}
      />
    </div>
  );
}
