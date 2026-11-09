export default function Avatar({ src, name, size = 96 }) {
  const style = { width: size, height: size, fontSize: size / 2.4 };
  if (src) return <img className="account-avatar" style={style} src={src} alt="" width={size} height={size} />;
  return (
    <span className="account-avatar" style={style} aria-hidden="true">
      {(name || '?').trim().charAt(0).toUpperCase()}
    </span>
  );
}
