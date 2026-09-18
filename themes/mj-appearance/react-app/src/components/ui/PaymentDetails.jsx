/**
 * Datos para pagar de un medio de pago: alias, CBU, titular… (plugin MJ Medios de Pago).
 *
 * Qué datos hay, cómo se llaman, cuáles llevan botón de copiar y qué dice ese
 * botón lo decide el admin en WooCommerce → Medios de pago; acá solo se
 * muestran. Sin datos no dibuja nada.
 *
 *   <PaymentDetails title="Datos para transferir" rows={[...]} texts={{ copy: 'Copiar', copied: 'Copiado' }} />
 */
import { useState } from 'react';
import { Check, Copy } from 'lucide-react';
import { cn } from '@/lib/format';
import { t } from '@/lib/copy';

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

/* Si el plugin no manda sus textos (una versión vieja), van los de Textos de la tienda. */

function DetailRow({ label, value, copy, texts }) {
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
          aria-label={`${copied ? texts.copied : texts.copy}: ${name}`}
        >
          {copied ? <Check size={13} /> : <Copy size={13} />}
          {copied ? texts.copied : texts.copy}
        </button>
      )}
    </li>
  );
}

export function PaymentDetails({ title = '', rows, texts, className = '' }) {
  const labels = { copy: texts?.copy || t('pay_details_copy'), copied: texts?.copied || t('pay_details_copied') };
  const list = (Array.isArray(rows) ? rows : []).filter((r) => r && (r.label || r.value));
  if (!list.length) return null;

  return (
    <div className={cn('mj-pay-details', className)}>
      {title && <p className="text-xs font-semibold uppercase tracking-[0.15em] text-ink-faint">{title}</p>}
      <ul className={cn('divide-y divide-line', title && 'mt-3')}>
        {list.map((r, i) => (
          <DetailRow key={`${r.label}-${i}`} label={r.label} value={r.value} copy={!!r.copy} texts={labels} />
        ))}
      </ul>
    </div>
  );
}
