-- ════════════════════════════════════════════════════════════════
-- VOLTIKA TRANSACCIONES RECOVERY
-- Generated: 2026-04-24 · from backup_2026-04-06.sql
-- Records to restore: 23 (original IDs 1-23)
-- 
-- SAFETY MEASURES:
--   1. Runs inside a TRANSACTION (can ROLLBACK)
--   2. Omits `id` column → auto-increment continues from current max
--   3. Current records (id 1-9, April 21-22) are UNTOUCHED
--   4. Uses INSERT IGNORE on pedido duplicates as extra safety
--   5. Pre/post count checks to verify
-- 
-- HOW TO APPLY:
--   Option A) phpMyAdmin → Database → Import tab → choose this file
--   Option B) mysql -u voltika -p voltika_ < recovery_transacciones_2026-04-24.sql
-- ════════════════════════════════════════════════════════════════

START TRANSACTION;

-- Show count BEFORE recovery
SELECT COUNT(*) AS `count_before_recovery` FROM `transacciones`;

-- ── Restore 23 records from backup (old schema → new schema) ──
-- Columns explicitly listed. New columns (referido_id, referido_tipo,
-- caso, folio_contrato) are omitted and will default to NULL.

INSERT IGNORE INTO `transacciones` (`pedido`, `referido`, `nombre`, `telefono`, `email`, `razon`, `rfc`, `direccion`, `ciudad`, `estado`, `cp`, `e_nombre`, `e_telefono`, `e_direccion`, `e_ciudad`, `e_estado`, `e_cp`, `modelo`, `color`, `tpago`, `tenvio`, `precio`, `penvio`, `total`, `freg`, `stripe_pi`) VALUES
('1756526853', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', '', '', '', '', '', '', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '44790', '0', '44790', '2025-08-30 / 04:07', NULL),
('1756527543', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', '', '', '', '', '', '', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '44790', '0', '44790', '2025-08-30 / 04:19', NULL),
('1756528241', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', 'GFKIHVL', '3054196', 'PRV DE LA LUZ FÍSICA', 'TREJO', 'HGLYUFCLUTFLUI', '52255', 'Voltika Tromox M05', 'Gris Espacial', 'Tarjeta de débito o crédito', 'Envío a domicilio', '51550', '0', '51550', '2025-08-30 / 04:30', NULL),
('1756529044', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', 'GFKIHVL', '62981619', 'PRV DE LA LUZ FÍSICA', 'TREJO', 'HGLYUFCLUTFLUI', '52255', 'Voltika Tromox M05', 'Gris Espacial', 'Tarjeta de débito o crédito', 'Envío a domicilio', '51550', '0', '51550', '2025-08-30 / 04:44', NULL),
('1757993102', '', 'Oscar Limón', '4421198928', 'oscar@dealup.mx', 'Razon', 'LUGO874793234', 'Calle', 'Qro', 'Qro', '76060', '', '', '', '', '', '', 'Voltika Tromox Pesgo', 'Negro', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '36600', '0', '36600', '2025-09-16 / 03:25', NULL),
('1757993584', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', '', '', '', '', '', '', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '48260', '0', '48260', '2025-09-16 / 03:33', NULL),
('1757994138', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', 'GFKIHVL', '5530527362', 'PRV DE LA LUZ FÍSICA', 'Huixquilucan de Degollado', 'México', '52790', 'Voltika Tromox M05', 'Plateado cósmico', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '0', '48260', '2025-09-16 / 03:42', NULL),
('1761168733', '', 'Mario Gerardo González Marín', '5560880769', 'mariogerardotbj@hotmail.com', 'Mario Gerardo González Marín ', 'GOMM040721E19', 'Carretones 135 Edificio 9 Depto. 001', 'CDMX', 'Ciudad de México', '15810', 'Mario Gerardo González Marín', '5560880769', 'Carretones 135 Edificio 9 Depto. 001', 'Ciudad de México', 'Ciudad de México', '15810', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '0', '48260', '2025-10-22 / 21:32', NULL),
('1762799790', '', 'José Enrique Kánter Paniagua ', '9671659650', 'enrique_kanter21@hotmail.com', 'José Enrique Kánter Paniagua ', 'KAPE870112JSA', 'Primero de marzo 41', 'San Cristóbal de Las Casas', 'Chiapas', '29240', 'José Enrique Kánter Paniagua ', '9671659650', 'Calle Primero de Marzo 41', 'San Cristóbal de las Casas', 'Chiapas', '29240', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '3950', '52210', '2025-11-10 / 18:36', NULL),
('1762989795', '', 'Francisco Ablanedo Guajardo ', '5535679706', 'pachis24@icloud.com', 'Santiago Zarazua Baig ', 'ZABS060515CT1', 'Hacienda Campo Bravo #41', 'Huixquilucan', 'Estado de México  ', '52763', 'Francisco Ablanedo Guajardo ', '5535679706', 'Avenida Jesús del monte #34 Residencial isla de agua ', 'Huixquilucan de Degollado', 'México', '52764', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '0', '48260', '2025-11-12 / 23:23', NULL),
('1764810641', '', 'ISRAEL AMAURI HERNÁNDEZ SALAS', '8113231710', 'amaury.hdzs@gmail.com', 'ISRAEL AMAURI HERNÁNDEZ SALAS', 'HESI940826D27', 'PASEO DEL BOSQUE 110, COL. VALLE DE LA SIERRA', 'SANTA CATARINA', 'NUEVO LEÓN', '66165', 'ISRAEL AMAURI HERNÁNDEZ SALAS', '8113231710', 'PASEO DEL BOSQUE 110', 'Ciudad Santa Catarina', 'Nuevo León', '66165', 'Voltika Tromox M05', 'Plateado cósmico', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '1800', '50060', '2025-12-04 / 01:10', NULL),
('1770428461', 'DIRECTO', 'CONCEPCION ISRAEL GARCIA AMARILLAS', '6441599499', 'cisrael1@gmail.com', 'CONCEPCION ISRAEL GARCIA AMARILLAS', 'GAAC8112088R1', 'real de badajoz 2023', 'Cajeme', 'Sonora', '85098', 'CONCEPCION ISRAEL GARCIA AMARILLAS', '6441599499', 'real de badajoz 2023', 'Ciudad Obregón', 'Sonora', '85098', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '4800', '53060', '2026-02-07 / 01:41', NULL),
('1772420984', 'Directo', 'Josue Alberto Villa Lechuga', '5538335592', 'vjosue.unotv@gmail.com', 'Josué Villa', 'VILJ900303SK7', 'Villa Panamericana RNDA Fauna Edif Antilope 203', 'Ciudad de México', 'CDMX', '04700', 'Josue Alberto Villa Lechuga', '5538335592', 'Calle Mixtecas Lote 96. Alcaldía Coyoacán', 'Ciudad de México', 'Ciudad de México', '04300', 'Voltika Tromox M03', 'Negro Profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '39900', '0', '39900', '2026-03-02 / 03:09', NULL),
('1772489560', 'Directo', 'Magdel Alejandro Gómez Pérez ', '5544904539', 'alejandro7809@live.com.mx', 'Magdel Alejandro Gomez Perez ', 'GOPM960325JX4', 'Cda.priv de pino Mz.1 Lt.8', 'CDMX', 'CDMX ', '14490', '', '', '', 'Ciudad de México', 'Ciudad de México', '14490', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en Santa Fe', '48260', '0', '48260', '2026-03-02 / 22:12', NULL),
('1774575788', '09860', 'angel rodriguez', '5648458802', 'angelorodx015@gmail.com', 'miguel angel rodriguez gonzalez', 'ROGM890330', 'sabadel 94', 'Iztapalapa', 'Ciudad de México', '09860', 'angel rodriguez', '5648458802', 'sabadel 94', 'Ciudad de México', 'Ciudad de México', '09860', 'Voltika Tromox Ukko S+', 'Azul juvenil', 'Tarjeta de débito o crédito', 'Envío a domicilio', '89900', '1500', '91400', '2026-03-27 / 01:43', NULL),
('1775401820', '', 'Johnson', '5056090195', 'aupwork00@gmail.com', '', '', '', 'Ciudad de México', 'Distrito Federal', '09820', '', '', '', '', '', '', 'M05', 'gris', 'enganche', '', '12065', '', '12065', '2026-04-05 15:10', 'pi_3TIsJaDPx1FQbvVS08LV8W8M'),
('1775408079', '', 'Rinolado marinando', '5514516605', 'dm@voltika.mx', '', '', '', 'Lerma', 'México', '52000', '', '', '', '', '', '', 'M05', 'plata', 'enganche', '', '12065', '', '12065', '2026-04-05 16:54', 'pi_3TItwYDPx1FQbvVS1GMz8maT'),
('1775413940', '', 'Contado contado', '5514216605', 'dm@voltika.mx', '', '', '', 'Tepic', 'Nayarit', '63000', '', '', '', '', '', '', 'M05', 'gris', 'unico', '', '48260', '', '48260', '2026-04-05 18:32', 'pi_3TIvT4DPx1FQbvVS02x3yUPb'),
('1775414052', '', 'John Doe', '5514516605', 'Dm@voltika.mx', '', '', '', 'Monterrey', 'Nuevo León', '65000', '', '', '', '', '', '', 'M05', 'gris', 'msi', '', '5562', '', '48260', '2026-04-05 18:34', 'pi_3TIvUtDPx1FQbvVS1tKdL8V6'),
('1775485653', '', 'Hhjhghj', '5514516605', 'dm@voltika.mx', '', '', '', 'Monterrey', 'Nuevo León', '65000', '', '', '', '', '', '', 'M05', 'plata', 'enganche', '', '16891', '', '16891', '2026-04-06 14:27', 'pi_3TJE7jDPx1FQbvVS2hljeOnM'),
('1775496686', '', 'Patricio Jacinto', '5514516605', 'dm@voltika.mx', '', '', '', 'Ecatepec de Morelos', 'México', '55100', '', '', '', '', '', '', 'M05', 'plata', 'enganche', '', '14478', '', '14478', '2026-04-06 17:31', 'pi_3TJGzhDPx1FQbvVS0Po7PYVO'),
('1775497188', '', 'Raúl González Pérez', '5514516605', 'dm@voltika.mx', '', '', '', 'Monterrey', 'Nuevo León', '65000', '', '', '', '', '', '', 'M05', 'negro', 'msi', '', '5562', '', '48260', '2026-04-06 17:39', 'pi_3TJH7nDPx1FQbvVS0RM32zbc'),
('1775502429', '', 'David', '5514516605', 'dm@voltika.mx', '', '', '', 'Ciudad de México', 'Distrito Federal', '11510', '', '', '', '', '', '', 'M05', 'gris', 'enganche', '', '12065', '', '12065', '2026-04-06 19:07', 'pi_3TJIUKDPx1FQbvVS0jUk7bcZ');

-- Show count AFTER recovery
SELECT COUNT(*) AS `count_after_recovery` FROM `transacciones`;

-- Verify specific restored records (sample checks)
SELECT `id`, `pedido`, `nombre`, `freg`, `total`, `stripe_pi`
FROM `transacciones`
WHERE `pedido` IN ('1756526853', '1775502429', '1775414052')
ORDER BY `id`;

-- ════════════════════════════════════════════════════════════════
-- IMPORTANT: Review the SELECT output above BEFORE running COMMIT
-- If something looks wrong, run ROLLBACK instead of COMMIT
-- ════════════════════════════════════════════════════════════════

-- Uncomment ONE of the following after verification:
-- COMMIT;      -- ✓ Applies the recovery permanently
-- ROLLBACK;    -- ✗ Discards all changes if something's wrong
