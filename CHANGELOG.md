# Changelog

## 1.1.0 — 2026-11-26

- Rejilla: columna con el IVA de cada producto (regla y %).
- Rejilla: iconos para abrir el producto en la tienda o para editarlo, en pestaña nueva.
- Tabla de tarifas: botones para ver el grupo con sus clientes o editarlo, en pestaña nueva.
- Rejilla: avisos en rojo cuando la tarifa vale 0 o es mayor que el PVP, con filtro «Solo con avisos».
- Rejilla: ordenar pinchando en la cabecera de las columnas, y filtros por categoría (con subcategorías) y por marca.
- Rellenar en bloque: a todos los productos del filtro, tarifa = PVP u otra tarifa ± %, con redondeo (0,05, 0,10, euro, ,95, ,99), solo a los que no tienen precio o a todos. Primero enseña cuántos cambia y unos ejemplos.
- Ficha del producto (pestaña Módulos): su precio en cada tarifa, editable ahí mismo con su botón Guardar.
- Historial de cambios de precio: fecha, empleado, tarifa, antes, después y origen (rejilla, ficha, CSV o rellenar en bloque), con filtros. Se guardan los dos últimos años.
- Exportar lo filtrado: CSV con los productos del filtro y solo la tarifa elegida, listo para volver a importar.
- La importación CSV y los ajustes se envían a la misma dirección en la que está abierta la configuración, para que no se pierda el archivo en las redirecciones de PS 8.

## 1.0.2 — 2026-11-25

- Arreglado: la pestaña «Precios por tarifa» no cargaba los productos y daba «El servidor no ha devuelto una respuesta válida».
- Si vuelve a fallar la carga, el aviso enseña lo que ha contestado el servidor, para saber el motivo.

## 1.0.1 — 2026-11-25

- Arreglado: la instalación daba error 500 con el modo depuración activado (faltaba la parte del módulo que avisa de las actualizaciones).

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
