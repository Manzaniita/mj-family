/**
 * Subida del comprobante de pago.
 *
 * El widget lo trae el plugin MJ Medios de Pago (window.MJPM.uploader): la
 * subida, la validación de tipo y tamaño, el estado de revisión y el motivo del
 * rechazo los maneja él. Acá solo se le da un lugar en la SPA y se lo monta
 * cuando el nodo entra al DOM, porque el plugin se auto-monta al cargar la
 * página y en una SPA el contenedor aparece después.
 *
 * Sin ese plugin activo no dibuja nada, así que el tema sigue funcionando igual.
 *
 * Quién puede subir:
 *   - con cuenta: alcanza la sesión (el pedido tiene que ser suyo),
 *   - sin cuenta: hace falta el `token` firmado que devuelve el servidor a
 *     cambio de la clave del pedido (ver getReceiptToken en lib/wc.js).
 */
import { useEffect, useRef } from 'react';

export function ReceiptUploader({ context = 'order', objectId, token = '', title = '', className = '' }) {
  const ref = useRef(null);

  useEffect(() => {
    const node = ref.current;
    if (!node || !objectId) return;
    const uploader = window.MJPM?.uploader;
    if (!uploader) return;
    try {
      uploader.mount(node);
    } catch (e) {
      /* Que el widget falle no puede romper la página del pedido. */
    }
  }, [context, objectId, token]);

  if (!objectId) return null;
  if (typeof window !== 'undefined' && !window.MJPM?.uploader) return null;

  return (
    <div
      ref={ref}
      className={className}
      data-mjpm-uploader
      data-context={context}
      data-objeto={objectId}
      data-token={token}
      data-title={title}
    />
  );
}
