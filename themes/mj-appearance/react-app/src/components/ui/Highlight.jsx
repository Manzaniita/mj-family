/**
 * Resalta las coincidencias de `query` dentro de `text` (case-insensitive y
 * sin distinguir tildes). Lo usa el buscador para marcar dónde matcheó.
 */
const norm = (s) =>
  (s || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

export function Highlight({ text, query, className = '' }) {
  if (!text) return null;
  const q = norm((query || '').trim());
  if (!q) return text;

  const haystack = norm(text);
  const parts = [];
  let i = 0;
  while (i < haystack.length) {
    const idx = haystack.indexOf(q, i);
    if (idx === -1) {
      parts.push(text.slice(i));
      break;
    }
    if (idx > i) parts.push(text.slice(i, idx));
    parts.push(
      <mark
        key={idx}
        className={`rounded-[3px] bg-accent/25 px-px text-inherit ${className}`}
      >
        {text.slice(idx, idx + q.length)}
      </mark>
    );
    i = idx + q.length;
  }
  return parts;
}
