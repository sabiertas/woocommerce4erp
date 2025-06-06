# WooCommerce4AGC

## Estado actual (junio 2024)

- **Panel de administración**: UI moderna, minimalista, con pestañas para Panel, Ajustes, Cronjobs, Logs y Consultas. Estilos corporativos (azul marino y naranja).
- **Sincronización**: Productos, precios y stock se sincronizan desde el ERP AGC a WooCommerce.
    - La sincronización se realiza por AJAX desde el dashboard, mostrando spinner y overlay de "Sincronizando... Esto puede tardar".
    - Cada sincronización recorre todos los productos/variaciones de WooCommerce y consulta el ERP por cada SKU, solicitando solo los campos estrictamente necesarios.
    - El resultado se muestra en la tarjeta correspondiente, con resumen y errores si los hay.
- **Logs**: Visualización y borrado de logs por módulo, con botón rojo y confirmación.
- **Cronjobs**: Panel de cronjobs con switches, frecuencia editable, badges de estado y guardado robusto.
- **Modo depuración**: Switch en ajustes. Cuando está activo, los errores muestran información extendida en logs y consultas manuales.

## Decisiones técnicas clave
- **Sincronización individual por SKU**: Evita timeouts y sobrecarga en catálogos grandes. Solo se piden los campos necesarios al ERP.
- **AJAX para sincronización manual**: Mejor experiencia de usuario, feedback visual inmediato, sin recargar la página ni bloquear la UI.
- **Paneles y feedback visual**: Spinner, overlays, mensajes de éxito/error y deshabilitado de botones durante la operación.

## Cómo continuar
1. **Probar la sincronización AJAX** con catálogos reales y revisar logs/errores.
2. **Optimizar rendimiento**: Si sigue habiendo lentitud, considerar paginación, lotes o colas en background (Action Scheduler).
3. **Mejorar feedback de progreso**: Mostrar barra de progreso o contador de productos procesados si es viable.
4. **Ampliar integración**: Añadir más tablas/campos del ERP según necesidades futuras.
5. **Revisar seguridad y permisos**: Validar nonces, roles y sanitización de datos en endpoints AJAX.
6. **Documentar endpoints y estructura**: Mantener este README actualizado con cambios clave y decisiones.

---

**Para retomar:**
- Revisa este README y los últimos commits para ver el estado exacto.
- El dashboard es el punto de entrada para pruebas y nuevas mejoras.
- Si hay dudas sobre la lógica de sincronización, consulta `ProductSync.php`, `PriceSync.php`, `StockSync.php` y `ERPClient.php`.

¡Buen trabajo y ánimo con la siguiente fase! 🚀 