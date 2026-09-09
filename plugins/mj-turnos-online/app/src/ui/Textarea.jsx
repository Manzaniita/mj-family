import { forwardRef } from 'react';
import { cn } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { Field } from './Field';
import { controlBase } from './styles';

/**
 * Área de texto.
 * @param {{ label?, help?, error?, required?, id?, rows?, className?, textareaClassName?, ...textarea }} props
 */
export const Textarea = forwardRef(function Textarea(
  { label, help, error, required, id, rows = 3, className, textareaClassName, hint, ...rest },
  ref
) {
  const uid = useUid('mjto-ta');
  const taId = id || uid;
  return (
    <Field label={label} help={help} error={error} required={required} htmlFor={taId} hint={hint} className={className}>
      <textarea
        ref={ref}
        id={taId}
        rows={rows}
        aria-invalid={error ? 'true' : undefined}
        aria-required={required || undefined}
        className={cn(controlBase, 'min-h-20 px-4 py-2.5 text-[15px] leading-relaxed', textareaClassName)}
        {...rest}
      />
    </Field>
  );
});
