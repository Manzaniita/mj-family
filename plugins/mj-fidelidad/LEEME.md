# MJ Fidelidad

Tarjeta de sellos para las clientas. Cada turno atendido suma sellos solo; al completar la tarjeta, la clienta gana el premio que elijas. No necesita ningún otro plugin: con MJ Turnos Online los sellos llegan solos, con WooCommerce también pueden sumar las compras, y sin ninguno de los dos los cargás a mano.

## Cómo funciona

1. **La tarjeta** tiene la cantidad de sellos que elijas (10 por defecto). Al completarla se gana el premio y arranca una nueva; los sellos de más pasan a la siguiente. Los sellos no vencen, salvo que pongas un vencimiento en meses.
2. **Los sellos se suman solos**:
   - **Turnos** (MJ Turnos Online): cuando marcás un turno como "Atendida". Cada servicio da 1 sello, o los que pongas en su ficha (Turnos → Servicios, cuadro *Fidelidad*): un combo puede dar 2, y un servicio con 0 no suma. Si le sacás el "atendido", el sello se descuenta.
   - **Compras** (WooCommerce, apagado por defecto): cada compra completada de una cuenta suma una cantidad fija o un sello por cada monto gastado. Si el pedido se cancela o se reembolsa, se descuenta.
   - **A mano**: en *Fidelidad → Clientas* sumás o descontás sellos con un motivo.
3. **El premio** puede ser un texto que entregás vos ("Un lash lifting de regalo") y marcás como canjeado, o **saldo a favor** para el próximo turno (con MJ Turnos y su saldo a favor prendido): se suma solo al completar la tarjeta.
4. **Avisos**: a la clienta le llega un mail cuando completa la tarjeta, y a vos otro.

## Quién tiene la tarjeta

La tarjeta es de la cuenta de la clienta. Si reservó turnos sin cuenta, sus sellos quedan en su ficha de MJ Turnos y se suman a su cuenta cuando esa ficha se vincula a una (sola, al reservar con cuenta, o a mano desde la ficha del panel). Nada se pierde ni se duplica.

## Dónde la ve la clienta

- **Con el tema MJ Appearance**: en *Mi cuenta*.
- **Con cualquier tema**: en la página donde pongas el shortcode `[mj_fidelidad]`.
- Solo con la sesión iniciada; nadie ve la tarjeta de otra persona.

## Qué configurar (Fidelidad → Ajustes)

- **La tarjeta**: prendida o no, sellos por tarjeta, vencimiento.
- **El premio**: texto o saldo a favor, y el monto.
- **De dónde salen los sellos**: turnos (y cuántos por turno), compras (por compra o por monto) y los motivos que quedan en los movimientos.
- **Textos de la tarjeta** y **mails** (con comodines).
- **Al borrar el plugin**: si se borran también los sellos y los premios (por defecto quedan).

## Prueba corta

1. En *Fidelidad → Ajustes* poné 3 sellos por tarjeta y el premio "Prueba".
2. Con MJ Turnos: marcá tres turnos de una misma clienta como atendidos.
3. En *Fidelidad → Clientas* la clienta aparece con la tarjeta completa y un premio "Para canjear"; te llegan los dos mails. Marcalo como canjeado.
4. Sacale el "atendido" a uno de los turnos: vuelve a 2 de 3 (el premio ya canjeado no se toca).

## Bueno saber

- Cambiar la cantidad de sellos por tarjeta recalcula las tarjetas en curso; los premios ya ganados quedan.
- Los mails salen con el correo de WordPress: si no llegan, instalá un plugin de SMTP.
- Al desactivar el plugin no se borra nada. Al borrarlo, tampoco, salvo que lo marques en los ajustes.
