# Changelog

## 1.0.0 — 2026-11-25

Primera versión.

- Cada grupo de clientes marcado como tarifa tiene su propia lista de precios fijos por producto. El cliente ve los de su grupo por defecto, y las combinaciones suman su impacto.
- Los productos sin precio en la tarifa se venden a su precio normal.
- Los descuentos nativos (precios específicos, escalados por cantidad, reglas de catálogo) se aplican encima del precio de tarifa. Carrito, pedido y reglas de carrito usan el precio de tarifa.
- PVP original en pequeño en la ficha y en los listados, para los clientes con tarifa: texto, color, tamaño, tachado y posición configurables, o desactivado.
- Rejilla por tarifa con buscador, filtro y edición del precio en línea (pinchar, cambiar e Intro).
- Exportación e importación CSV por referencia: una columna por tarifa, celda vacía para quitar el precio y aviso de las columnas que no son tarifa. Precios con o sin IVA, a elegir.
- Avisos si un grupo de tarifa tiene reducción por % o por categoría.
- Actualizaciones desde el servidor de actualizaciones de josra.
- Requiere PrestaShop 8.2 o superior. No es compatible con multitienda.
