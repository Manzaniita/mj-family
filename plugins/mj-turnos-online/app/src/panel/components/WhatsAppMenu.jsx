/**
 * Menús flotantes del panel + menú de WhatsApp.
 *
 * - PopMenu: primitiva compartida (la usa también AppointmentActions). En
 *   pantallas ≥ 40rem es un popover anclado al disparador; en el celu, una
 *   hoja desde abajo (Modal). Los ítems con `href` se abren en otra pestaña
 *   (links wa.me) y los demás llaman a `onSelect`.
 * - WhatsAppMenu: arma los ítems con las plantillas del turno
 *   (appointment.whatsapp.links: confirm, deposit, reminder, control, retouch,
 *   generic) o, si sólo hay clienta, un mensaje libre a su número.
 *
 * Los textos de este archivo son del panel (uso privado de la dueña); el
 * contenido de cada mensaje sale de las plantillas tpl_wa_* configuradas.
 */
import { cloneElement, isValidElement, useCallback, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Bell, CalendarCheck, Coins, MessageCircle, MessageSquareText, Scissors, Sparkles } from 'lucide-react';
import { cn, fullName, prettyPhone } from '@/lib/format';
import { useClickOutside, useDialog, useMediaQuery } from '@/lib/a11y';
import { Button, Modal, Portal } from '@/ui';
import { usePanel } from '../store';

/* ------------------------------------------------------------------------- */
/* PopMenu                                                                   */
/* ------------------------------------------------------------------------- */

/**
 * Un ítem del menú.
 * @typedef {{ key: string, label: string, icon?: Component, href?: string, onSelect?: () => void,
 *   tone?: 'danger'|'accent', hint?: string, disabled?: boolean, divider?: boolean }} MenuItem
 */

function MenuItem({ item, onClose, sheet }) {
  const Icon = item.icon;
  const cls = cn(
    'mjto-menu__item flex w-full items-center gap-3 text-left font-medium transition-colors disabled:pointer-events-none disabled:opacity-40',
    sheet ? 'h-13 px-5 text-[15px]' : 'h-10 rounded-xl px-3 text-sm',
    item.tone === 'danger'
      ? 'text-danger hover:bg-danger-soft'
      : item.tone === 'accent'
        ? 'text-accent hover:bg-accent-soft'
        : 'text-ink hover:bg-surface-2'
  );
  const inner = (
    <>
      {Icon && <Icon size={sheet ? 20 : 17} className="shrink-0 opacity-80" aria-hidden="true" />}
      <span className="min-w-0 flex-1 truncate">{item.label}</span>
      {item.hint && <span className="shrink-0 text-xs text-ink-faint">{item.hint}</span>}
    </>
  );
  const handle = () => {
    item.onSelect?.();
    onClose?.();
  };
  return (
    <>
      {item.divider && <div role="separator" className="mjto-hairline-t my-1" />}
      {item.href && !item.disabled ? (
        <a role="menuitem" href={item.href} target="_blank" rel="noopener noreferrer" className={cls} onClick={handle}>
          {inner}
        </a>
      ) : (
        <button type="button" role="menuitem" disabled={item.disabled} className={cls} onClick={handle}>
          {inner}
        </button>
      )}
    </>
  );
}

/** Popover anclado (desktop). Posición fija calculada desde el disparador; se da vuelta si no entra abajo. */
function PopoverMenu({ open, onClose, anchorRef, items, align, width, label }) {
  const ref = useRef(null);
  const [node, setNode] = useState(null);
  const [style, setStyle] = useState(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  const setRef = useCallback((el) => {
    ref.current = el;
    setNode(el);
  }, []);

  useLayoutEffect(() => {
    if (!open || !node) {
      setStyle(null);
      return undefined;
    }
    const compute = () => {
      const a = anchorRef?.current;
      if (!a || !node) return;
      const r = a.getBoundingClientRect();
      const vh = window.innerHeight;
      const vw = window.innerWidth;
      const h = node.offsetHeight;
      const w = node.offsetWidth;
      const below = vh - r.bottom - 8;
      const flip = h > below && r.top - 8 > below;
      const next = { position: 'fixed', width, zIndex: 95 };
      if (flip) next.bottom = Math.max(8, vh - r.top + 6);
      else next.top = Math.max(8, r.bottom + 6);
      if (align === 'right') next.right = Math.max(8, vw - r.right);
      else next.left = Math.min(Math.max(8, r.left), Math.max(8, vw - w - 8));
      next.transformOrigin = `${flip ? 'bottom' : 'top'} ${align}`;
      setStyle(next);
    };
    compute();
    const onMove = (e) => {
      // Scroll adentro del propio menú no lo cierra.
      if (e?.type === 'scroll' && node.contains(e.target)) return;
      closeRef.current?.();
    };
    window.addEventListener('resize', onMove);
    window.addEventListener('scroll', onMove, true);
    return () => {
      window.removeEventListener('resize', onMove);
      window.removeEventListener('scroll', onMove, true);
    };
  }, [open, node, anchorRef, align, width]);

  useClickOutside(ref, open, (e) => {
    if (anchorRef?.current && anchorRef.current.contains(e.target)) return;
    onClose?.();
  });
  useDialog(ref, open, onClose);

  const onKeyDown = (e) => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const els = ref.current ? [...ref.current.querySelectorAll('[role="menuitem"]:not([disabled])')] : [];
    if (!els.length) return;
    e.preventDefault();
    const i = els.indexOf(document.activeElement);
    const n = e.key === 'ArrowDown' ? (i + 1) % els.length : (i - 1 + els.length) % els.length;
    els[n].focus();
  };

  if (!open) return null;

  return (
    <Portal>
      <div
        ref={setRef}
        role="menu"
        aria-label={label}
        tabIndex={-1}
        onKeyDown={onKeyDown}
        style={style || { position: 'fixed', top: 0, left: 0, width, visibility: 'hidden' }}
        className="mjto-popover mjto-menu max-h-[70vh] overflow-y-auto p-1.5 outline-none"
      >
        {items.map((item) => (
          <MenuItem key={item.key} item={item} onClose={onClose} />
        ))}
      </div>
    </Portal>
  );
}

/** Hoja desde abajo (mobile). */
function SheetMenu({ open, onClose, items, title, description }) {
  return (
    <Modal open={open} onClose={onClose} size="sm" title={title} description={description} noPadding bodyClassName="pb-2">
      <div role="menu" className="mjto-menu flex flex-col py-1">
        {items.map((item) => (
          <MenuItem key={item.key} item={item} onClose={onClose} sheet />
        ))}
      </div>
    </Modal>
  );
}

/**
 * Menú flotante controlado.
 * @param {{ open: boolean, onClose: () => void, anchorRef: React.RefObject<HTMLElement>, items: MenuItem[],
 *   title?: string, description?: string, align?: 'right'|'left', width?: string }} props
 */
export function PopMenu({ open, onClose, anchorRef, items = [], title, description, align = 'right', width = '16rem' }) {
  const desktop = useMediaQuery('(min-width: 40rem)');
  const list = items.filter(Boolean);
  if (desktop) {
    return <PopoverMenu open={open} onClose={onClose} anchorRef={anchorRef} items={list} align={align} width={width} label={title} />;
  }
  return <SheetMenu open={open} onClose={onClose} items={list} title={title} description={description} />;
}

/* ------------------------------------------------------------------------- */
/* WhatsApp                                                                  */
/* ------------------------------------------------------------------------- */

/** Plantillas disponibles (orden del menú). Las claves son las de mjto_whatsapp_links(). */
export const WA_TEMPLATES = [
  { key: 'confirm', label: 'Confirmar turno', icon: CalendarCheck },
  { key: 'deposit', label: 'Pedir la seña', icon: Coins },
  { key: 'reminder', label: 'Recordatorio', icon: Bell },
  { key: 'control', label: 'Control', icon: Sparkles },
  { key: 'retouch', label: 'Retoque', icon: Scissors },
  { key: 'generic', label: 'Mensaje libre', icon: MessageSquareText },
];

/**
 * Ítems de WhatsApp para un turno y/o una clienta. Usa `links` si viene,
 * si no `appointment.whatsapp.links`; el mensaje libre cae a
 * client.whatsapp.url o a un wa.me armado con el teléfono.
 * @param {{ appointment?: object, client?: object, links?: object }} args
 * @returns {MenuItem[]}
 */
export function useWhatsAppItems({ appointment, client, links } = {}) {
  const { whatsappLink } = usePanel();
  return useMemo(() => {
    const c = client || appointment?.client || null;
    const map = { ...(appointment?.whatsapp?.links || {}), ...(links || {}) };
    const phone = c?.phone || c?.phoneRaw || '';
    const generic = map.generic || client?.whatsapp?.url || (phone ? whatsappLink(phone) : '');
    return WA_TEMPLATES.map((t) => {
      const href = t.key === 'generic' ? generic : map[t.key];
      return href ? { key: t.key, label: t.label, icon: t.icon, href } : null;
    }).filter(Boolean);
  }, [appointment, client, links, whatsappLink]);
}

/**
 * Botón "WhatsApp" con menú de plantillas.
 * @param {{ appointment?: object, client?: object, links?: object, trigger?: ReactElement (se le inyectan ref/onClick),
 *   label?: string, iconOnly?: boolean, size?: string, variant?: string, align?: 'right'|'left', className? }} props
 */
export function WhatsAppMenu({ appointment, client, links, trigger, label = 'WhatsApp', iconOnly = true, size = 'sm', variant = 'success', align = 'right', className }) {
  const items = useWhatsAppItems({ appointment, client, links });
  const [open, setOpen] = useState(false);
  const anchor = useRef(null);
  const c = client || appointment?.client || null;
  const name = fullName(c);
  const phone = c ? prettyPhone(c.phoneRaw || c.phone) : '';
  const disabled = !items.length;

  const triggerProps = {
    ref: anchor,
    onClick: (e) => {
      e?.stopPropagation?.();
      setOpen((o) => !o);
    },
    'aria-haspopup': 'menu',
    'aria-expanded': open,
    disabled,
    title: disabled ? 'La clienta no tiene teléfono' : label,
  };

  const triggerEl = isValidElement(trigger) ? (
    cloneElement(trigger, triggerProps)
  ) : (
    <Button variant={variant} size={size} icon={MessageCircle} iconOnly={iconOnly} aria-label={label} className={cn('mjto-wa-btn', className)} {...triggerProps}>
      {label}
    </Button>
  );

  return (
    <>
      {triggerEl}
      <PopMenu open={open} onClose={() => setOpen(false)} anchorRef={anchor} items={items} title={name ? `WhatsApp a ${name}` : 'WhatsApp'} description={phone} align={align} />
    </>
  );
}

export default WhatsAppMenu;
