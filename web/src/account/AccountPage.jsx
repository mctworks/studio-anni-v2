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
  image_processing_failed: 'That file could not be read as an image. Try a JPEG or PNG.',
};

const loadBitmap = async (file) => {
  try {
    return await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch {
    return createImageBitmap(file);
  }
};

const shrink = async (file) => {
  try {
    const bmp = await loadBitmap(file);
    const side = Math.min(bmp.width, bmp.height);
    const size = Math.min(512, side);
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, size, size);
    ctx.drawImage(bmp, (bmp.width - side) / 2, (bmp.height - side) / 2, side, side, 0, 0, size, size);
    bmp.close?.();
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
    return blob ? new File([blob], 'avatar.jpg', { type: 'image/jpeg' }) : file;
  } catch {
    return file;
  }
};

const AvatarEditor = ({ profile, onChange }) => {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const pick = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    setError('');
    setBusy(true);
    const upload = await shrink(file);
    if (upload.size > 5 * 1024 * 1024) {
      setBusy(false);
      return setError(avatarMessages.too_large);
    }
    const r = await account.uploadAvatar(upload);
    setBusy(false);
    if (r.ok) return onChange(r.data.profile);
    const code = r.data?.error;
    setError(avatarMessages[code] ?? `Could not save that image (${code ?? `HTTP ${r.status}`}).`);
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
          <input type="file" accept="image/*" onChange={pick} disabled={busy} hidden />
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

const CreateProfile = ({ initialName, onCreated, onSignOut }) => {
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setBusy(true);
    const r = await account.createProfile({ username: f.get('username'), displayName: f.get('displayName') });
    setBusy(false);
    if (r.status === 422) return setErrors(r.data.fields || {});
    if (r.ok) onCreated(r.data.profile);
  };

  return (
    <form onSubmit={submit} className="account-form">
      <h2>Create your public profile</h2>
      <p className="account-note">Staff accounts need a profile to comment and sign the guestbook. Your username is permanent.</p>
      <Field label="Username (letters, numbers, underscore)" error={errors.username}>
        <input name="username" minLength={3} maxLength={24} pattern="[A-Za-z0-9_]{3,24}" required />
      </Field>
      <Field label="Display name" error={errors.displayName}>
        <input name="displayName" defaultValue={initialName || ''} maxLength={80} />
      </Field>
      <button className="page-nav-button" type="submit" disabled={busy}>Create profile</button>
      <p><button className="account-link" type="button" onClick={onSignOut}>Sign out</button></p>
    </form>
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
        setPhase(r.status === 404 ? 'create' : 'login');
      }
    });

  useEffect(() => {
    load();
  }, []);

  const staffBar = auth.staff ? (
    <p className="account-note">Signed in as {auth.user.role}. <Link to="/mimosa">Open MIMOSA</Link></p>
  ) : null;

  if (phase === 'loading') return <div className="container account-page"><p>Loading…</p></div>;

  if (phase === 'login') {
    return (
      <AuthFlow
        startAt={params.has('signup') ? 'register' : 'login'}
        onDone={(u) => {
          auth.setUser(u);
          load();
        }}
      />
    );
  }

  if (phase === 'create') {
    return (
      <div className="container account-page">
        {staffBar}
        <CreateProfile
          initialName={auth.user?.displayName}
          onCreated={(p) => {
            setProfile(p);
            setPhase('ready');
          }}
          onSignOut={auth.logout}
        />
      </div>
    );
  }

  return (
    <div className="container account-page">
      {staffBar}
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
