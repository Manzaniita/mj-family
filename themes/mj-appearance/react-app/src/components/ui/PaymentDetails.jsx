/**
 * Datos para pagar de un medio de pago: alias, CBU, titular… (plugin MJ Medios de Pago).
 *
 * Qué datos hay, cómo se llaman y cuáles llevan botón de copiar lo decide el
 * admin en WooCommerce → Medios de pago; acá solo se muestran. Sin datos no
 * dibuja nada.
 *
 *   <PaymentDetails title="Datos para transferir" rows={[{ label: 'Alias', value: 'mi.tienda', copy: true }]} />
 */
import { useState } from 'react';
import { Check, Copy } from 'lucide-react';
import { cn } from '@/lib/format';

/** Copia al portapapeles. Los navegadores de WhatsApp o Instagram a veces no dejan usar la API nueva. */
async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch {
      ok = false;
    }
    area.remove();
    return ok;
  }
}

function DetailRow({ label, value, copy }) {
  const [copied, setCopied] = useState(false);
  const onCopy = async () => {
    if (await copyText(value)) {
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    }
    /* Sin permiso de portapapeles el dato sigue visible para copiarlo a mano. */
  };
  const name = label || value;

  return (
    <li className="mj-pay-details__row flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
      <div className="min-w-0">
        {label && <span className="block text-xs text-ink-faint">{label}</span>}
        <span className="mj-pay-details__value block break-all text-sm font-medium text-ink select-all">{value}</span>
      </div>
      {copy && value && (
        <button
          type="button"
          onClick={onCopy}
          className={cn(
            'mj-pay-details__copy inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors',
            copied ? 'border-success/40 text-success' : 'border-line text-ink-soft hover:border-line-strong hover:text-ink'
          )}
          aria-label={copied ? `${name} copiado` : `Copiar ${name}`}
        >
          {copied ? <Check size={13} /> : <Copy size={13} />}
          {copied ? 'Copiado' : 'Copiar'}
        </button>
      )}
    </li>
  );
}

export function PaymentDetails({ title = '', rows, className = '' }) {
  const list = (Array.isArray(rows) ? rows : []).filter((r) => r && (r.label || r.value));
  if (!list.length) return null;

  return (
    <div className={cn('mj-pay-details', className)}>
      {title && <p className="text-xs font-semibold uppercase tracking-[0.15em] text-ink-faint">{title}</p>}
      <ul className={cn('divide-y divide-line', title && 'mt-3')}>
        {list.map((r, i) => (
          <DetailRow key={`${r.label}-${i}`} label={r.label} value={r.value} copy={!!r.copy} />
        ))}
      </ul>
    </div>
  );
}
