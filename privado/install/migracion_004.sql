-- migracion_004.sql — Cotización guardada en cada cargo en USD.
-- Importala UNA vez desde phpMyAdmin (pestaña SQL), después de la 001, 002 y 003.
-- Una instalación nueva con install.php ya trae la columna (no necesita esta migración).
--
-- 1) Agrega cargos.cotizacion: dólar (ARS por 1 USD) vigente el día en que se generó el cargo.
--    Los cargos en ARS la dejan en NULL. Los reportes la usan en lugar del dólar de hoy.
-- 2) Completa los cargos en USD que ya existen con la cotización que había vigente en la
--    fecha en que se crearon (última cotización del tipo de dólar configurado, anterior o
--    igual a esa fecha). Si no hay historial anterior a esa fecha, usa la cotización actual.
--    Si todavía no hay ninguna cotización guardada, el cargo queda en NULL y los reportes
--    usan la vigente.

ALTER TABLE cargos
    ADD COLUMN cotizacion DECIMAL(12,2) NULL AFTER moneda;

UPDATE cargos ca
SET ca.cotizacion = COALESCE(
    (SELECT c.valor_venta FROM cotizaciones c
      WHERE c.tipo = (SELECT valor FROM configuracion WHERE clave = 'dolar_tipo')
        AND c.creado_en <= ca.creado_en
      ORDER BY c.creado_en DESC, c.id DESC LIMIT 1),
    (SELECT c2.valor_venta FROM cotizaciones c2
      WHERE c2.tipo = (SELECT valor FROM configuracion WHERE clave = 'dolar_tipo')
      ORDER BY c2.creado_en DESC, c2.id DESC LIMIT 1)
)
WHERE ca.moneda = 'USD' AND ca.cotizacion IS NULL;
