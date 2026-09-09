import { forwardRef, useEffect, useState } from 'react';
import { cn } from '@/lib/format';
import { CUR } from '@/lib/config';
import { formatAmount, parseMoney, roundMoney } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { Field } from './Field';
import { controlBase, controlSizes } from './styles';

/**
 * Importe con el símbolo de la moneda. Mientras se edita acepta "1234,5" o
 * "1.234,50"; al salir formatea con los separadores configurados.
 * @param {{ value: number|''|null, onChange: (value: number|'') => void, label?, help?, error?, required?,
 *   placeholder?, currency?: object, allowEmpty?: boolean (default true → '' cuando se borra), min?: number,
 *   size?, className?, inputClassName?, id?, disabled? }} props
 */
export const MoneyInput = forwardRef(function MoneyInput(
  { value, onChange, label, help, error, required, placeholder, currency = CUR, allowEmpty = true, min, size = 'md', className, inputClassName, id, hint, onBlur, onFocus, ...rest },
  ref
) {
  const uid = useUid('mjto-money');
  const inputId = id || uid;
  const [focused, setFocused] = useState(false);
  const display = (v) => (v === '' || v === null || v === undefined ? '' : formatAmount(v, currency));
  const [text, setText] = useState(() => display(value));

  useEffect(() => {
    if (!focused) setText(display(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, focused, currency.decimals]);

  const commit = (raw) => {
    if (raw.trim() === '') {
      onChange?.(allowEmpty ? '' : 0);
      return;
    }
    let n = roundMoney(parseMoney(raw), currency);
    if (min !== undefined && n < min) n = min;
    onChange?.(n);
  };

  const symbol = currency.symbol ?? '$';
  const right = currency.position === 'right' || currency.position === 'right_space';

  return (
    <Field label={label} help={help} error={error} required={required} htmlFor={inputId} hint={hint} className={className}>
      <div className="relative flex items-center">
        {!right && <span className="pointer-events-none absolute left-4 text-sm font-semibold text-ink-faint">{symbol}</span>}
        <input
          ref={ref}
          id={inputId}
          type="text"
          inputMode="decimal"
          autoComplete="off"
          value={text}
          placeholder={placeholder ?? display(0)}
          aria-invalid={error ? 'true' : undefined}
          aria-required={required || undefined}
          onChange={(e) => {
            const raw = e.target.value.replace(/[^\d.,\-]/g, '');
            setText(raw);
            commit(raw);
          }}
          onFocus={(e) => {
            setFocused(true);
            onFocus?.(e);
          }}
          onBlur={(e) => {
            // Al perder el foco el efecto de arriba vuelve a formatear con `value`.
            setFocused(false);
            onBlur?.(e);
          }}
          className={cn(controlBase, controlSizes[size] || controlSizes.md, 'mjto-tabular', right ? 'pr-9' : 'pl-9', inputClassName)}
          {...rest}
        />
        {right && <span className="pointer-events-none absolute right-4 text-sm font-semibold text-ink-faint">{symbol}</span>}
      </div>
    </Field>
  );
});
