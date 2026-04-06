-- Voltika DB Backup
-- Date: 2026-04-06 19:08:09
-- Tables: pedidos, transacciones, facturacion, consultas_buro, preaprobaciones, verificaciones_identidad

SET FOREIGN_KEY_CHECKS=0;

-- --------------------------------------------------------
-- Table: `pedidos`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `pedidos`;
CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_num` varchar(20) DEFAULT NULL,
  `nombre` varchar(200) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `modelo` varchar(200) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `metodo` varchar(50) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `estado` varchar(100) DEFAULT NULL,
  `cp` varchar(10) DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `freg` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

INSERT INTO `pedidos` VALUES
('1', 'VK-TBFQFW', 'Juan Perez', 'test@example.com', '5551234567', 'M05', 'Negro', '', 'Guadalajara', 'Jalisco', '44100', '48260.00', '2026-03-05 16:43:08'),
('2', 'VK-TBFYO3', 'Test User', 'test@test.com', '5512345678', 'VK1', 'Negro', 'contado', 'CDMX', 'Ciudad de Mexico', '06600', '109900.00', '2026-03-05 19:40:51'),
('3', 'VK-TBGC8G', 'Indra test', 'aupwork00@gmail.com', '5056090195', 'M05', 'negro', 'contado', 'Ciudad de Mexico', 'CDMX', '06600', '50060.00', '2026-03-06 00:33:52'),
('4', 'VK-TBHRGY', 'Indra test', 'aupwork00@gmail.com', '5056090195', 'M05', 'negro', 'msi', 'Tijuana', 'Baja California', '22222', '50060.00', '2026-03-06 19:00:34');

-- --------------------------------------------------------
-- Table: `transacciones`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `transacciones`;
CREATE TABLE `transacciones` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `pedido` text NOT NULL,
  `referido` text NOT NULL,
  `nombre` text NOT NULL,
  `telefono` text NOT NULL,
  `email` text NOT NULL,
  `razon` text NOT NULL,
  `rfc` text NOT NULL,
  `direccion` text NOT NULL,
  `ciudad` text NOT NULL,
  `estado` text NOT NULL,
  `cp` text NOT NULL,
  `e_nombre` text NOT NULL,
  `e_telefono` text NOT NULL,
  `e_direccion` text NOT NULL,
  `e_ciudad` text NOT NULL,
  `e_estado` text NOT NULL,
  `e_cp` text NOT NULL,
  `modelo` text NOT NULL,
  `color` text NOT NULL,
  `tpago` text NOT NULL,
  `tenvio` text NOT NULL,
  `precio` text NOT NULL,
  `penvio` text NOT NULL,
  `total` text NOT NULL,
  `freg` text NOT NULL,
  `stripe_pi` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

INSERT INTO `transacciones` VALUES
('1', '1756526853', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', '', '', '', '', '', '', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '44790', '0', '44790', '2025-08-30 / 04:07', NULL),
('2', '1756527543', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', '', '', '', '', '', '', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '44790', '0', '44790', '2025-08-30 / 04:19', NULL),
('3', '1756528241', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', 'GFKIHVL', '3054196', 'PRV DE LA LUZ FÍSICA', 'TREJO', 'HGLYUFCLUTFLUI', '52255', 'Voltika Tromox M05', 'Gris Espacial', 'Tarjeta de débito o crédito', 'Envío a domicilio', '51550', '0', '51550', '2025-08-30 / 04:30', NULL),
('4', '1756529044', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', 'GFKIHVL', '62981619', 'PRV DE LA LUZ FÍSICA', 'TREJO', 'HGLYUFCLUTFLUI', '52255', 'Voltika Tromox M05', 'Gris Espacial', 'Tarjeta de débito o crédito', 'Envío a domicilio', '51550', '0', '51550', '2025-08-30 / 04:44', NULL),
('5', '1757993102', '', 'Oscar Limón', '4421198928', 'oscar@dealup.mx', 'Razon', 'LUGO874793234', 'Calle', 'Qro', 'Qro', '76060', '', '', '', '', '', '', 'Voltika Tromox Pesgo', 'Negro', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '36600', '0', '36600', '2025-09-16 / 03:25', NULL),
('6', '1757993584', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', '', '', '', '', '', '', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en CC Miyana Polanco', '48260', '0', '48260', '2025-09-16 / 03:33', NULL),
('7', '1757994138', '', 'alejandro sanxhez becerril', '5530527358', 'aleffroad94@gmail.com', 'hydujsis', 'SABA941011su', '131', 'huixquilucan', 'estado de mexico', '589689', 'GFKIHVL', '5530527362', 'PRV DE LA LUZ FÍSICA', 'Huixquilucan de Degollado', 'México', '52790', 'Voltika Tromox M05', 'Plateado cósmico', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '0', '48260', '2025-09-16 / 03:42', NULL),
('8', '1761168733', '', 'Mario Gerardo González Marín', '5560880769', 'mariogerardotbj@hotmail.com', 'Mario Gerardo González Marín ', 'GOMM040721E19', 'Carretones 135 Edificio 9 Depto. 001', 'CDMX', 'Ciudad de México', '15810', 'Mario Gerardo González Marín', '5560880769', 'Carretones 135 Edificio 9 Depto. 001', 'Ciudad de México', 'Ciudad de México', '15810', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '0', '48260', '2025-10-22 / 21:32', NULL),
('9', '1762799790', '', 'José Enrique Kánter Paniagua ', '9671659650', 'enrique_kanter21@hotmail.com', 'José Enrique Kánter Paniagua ', 'KAPE870112JSA', 'Primero de marzo 41', 'San Cristóbal de Las Casas', 'Chiapas', '29240', 'José Enrique Kánter Paniagua ', '9671659650', 'Calle Primero de Marzo 41', 'San Cristóbal de las Casas', 'Chiapas', '29240', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '3950', '52210', '2025-11-10 / 18:36', NULL),
('10', '1762989795', '', 'Francisco Ablanedo Guajardo ', '5535679706', 'pachis24@icloud.com', 'Santiago Zarazua Baig ', 'ZABS060515CT1', 'Hacienda Campo Bravo #41', 'Huixquilucan', 'Estado de México  ', '52763', 'Francisco Ablanedo Guajardo ', '5535679706', 'Avenida Jesús del monte #34 Residencial isla de agua ', 'Huixquilucan de Degollado', 'México', '52764', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '0', '48260', '2025-11-12 / 23:23', NULL),
('11', '1764810641', '', 'ISRAEL AMAURI HERNÁNDEZ SALAS', '8113231710', 'amaury.hdzs@gmail.com', 'ISRAEL AMAURI HERNÁNDEZ SALAS', 'HESI940826D27', 'PASEO DEL BOSQUE 110, COL. VALLE DE LA SIERRA', 'SANTA CATARINA', 'NUEVO LEÓN', '66165', 'ISRAEL AMAURI HERNÁNDEZ SALAS', '8113231710', 'PASEO DEL BOSQUE 110', 'Ciudad Santa Catarina', 'Nuevo León', '66165', 'Voltika Tromox M05', 'Plateado cósmico', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '1800', '50060', '2025-12-04 / 01:10', NULL),
('12', '1770428461', 'DIRECTO', 'CONCEPCION ISRAEL GARCIA AMARILLAS', '6441599499', 'cisrael1@gmail.com', 'CONCEPCION ISRAEL GARCIA AMARILLAS', 'GAAC8112088R1', 'real de badajoz 2023', 'Cajeme', 'Sonora', '85098', 'CONCEPCION ISRAEL GARCIA AMARILLAS', '6441599499', 'real de badajoz 2023', 'Ciudad Obregón', 'Sonora', '85098', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '48260', '4800', '53060', '2026-02-07 / 01:41', NULL),
('13', '1772420984', 'Directo', 'Josue Alberto Villa Lechuga', '5538335592', 'vjosue.unotv@gmail.com', 'Josué Villa', 'VILJ900303SK7', 'Villa Panamericana RNDA Fauna Edif Antilope 203', 'Ciudad de México', 'CDMX', '04700', 'Josue Alberto Villa Lechuga', '5538335592', 'Calle Mixtecas Lote 96. Alcaldía Coyoacán', 'Ciudad de México', 'Ciudad de México', '04300', 'Voltika Tromox M03', 'Negro Profundo', 'Tarjeta de débito o crédito', 'Envío a domicilio', '39900', '0', '39900', '2026-03-02 / 03:09', NULL),
('14', '1772489560', 'Directo', 'Magdel Alejandro Gómez Pérez ', '5544904539', 'alejandro7809@live.com.mx', 'Magdel Alejandro Gomez Perez ', 'GOPM960325JX4', 'Cda.priv de pino Mz.1 Lt.8', 'CDMX', 'CDMX ', '14490', '', '', '', 'Ciudad de México', 'Ciudad de México', '14490', 'Voltika Tromox M05', 'Negro profundo', 'Tarjeta de débito o crédito', 'Recoger en Santa Fe', '48260', '0', '48260', '2026-03-02 / 22:12', NULL),
('15', '1774575788', '09860', 'angel rodriguez', '5648458802', 'angelorodx015@gmail.com', 'miguel angel rodriguez gonzalez', 'ROGM890330', 'sabadel 94', 'Iztapalapa', 'Ciudad de México', '09860', 'angel rodriguez', '5648458802', 'sabadel 94', 'Ciudad de México', 'Ciudad de México', '09860', 'Voltika Tromox Ukko S+', 'Azul juvenil', 'Tarjeta de débito o crédito', 'Envío a domicilio', '89900', '1500', '91400', '2026-03-27 / 01:43', NULL),
('16', '1775401820', '', 'Johnson', '5056090195', 'aupwork00@gmail.com', '', '', '', 'Ciudad de México', 'Distrito Federal', '09820', '', '', '', '', '', '', 'M05', 'gris', 'enganche', '', '12065', '', '12065', '2026-04-05 15:10', 'pi_3TIsJaDPx1FQbvVS08LV8W8M'),
('17', '1775408079', '', 'Rinolado marinando', '5514516605', 'dm@voltika.mx', '', '', '', 'Lerma', 'México', '52000', '', '', '', '', '', '', 'M05', 'plata', 'enganche', '', '12065', '', '12065', '2026-04-05 16:54', 'pi_3TItwYDPx1FQbvVS1GMz8maT'),
('18', '1775413940', '', 'Contado contado', '5514216605', 'dm@voltika.mx', '', '', '', 'Tepic', 'Nayarit', '63000', '', '', '', '', '', '', 'M05', 'gris', 'unico', '', '48260', '', '48260', '2026-04-05 18:32', 'pi_3TIvT4DPx1FQbvVS02x3yUPb'),
('19', '1775414052', '', 'John Doe', '5514516605', 'Dm@voltika.mx', '', '', '', 'Monterrey', 'Nuevo León', '65000', '', '', '', '', '', '', 'M05', 'gris', 'msi', '', '5562', '', '48260', '2026-04-05 18:34', 'pi_3TIvUtDPx1FQbvVS1tKdL8V6'),
('20', '1775485653', '', 'Hhjhghj', '5514516605', 'dm@voltika.mx', '', '', '', 'Monterrey', 'Nuevo León', '65000', '', '', '', '', '', '', 'M05', 'plata', 'enganche', '', '16891', '', '16891', '2026-04-06 14:27', 'pi_3TJE7jDPx1FQbvVS2hljeOnM'),
('21', '1775496686', '', 'Patricio Jacinto', '5514516605', 'dm@voltika.mx', '', '', '', 'Ecatepec de Morelos', 'México', '55100', '', '', '', '', '', '', 'M05', 'plata', 'enganche', '', '14478', '', '14478', '2026-04-06 17:31', 'pi_3TJGzhDPx1FQbvVS0Po7PYVO'),
('22', '1775497188', '', 'Raúl González Pérez', '5514516605', 'dm@voltika.mx', '', '', '', 'Monterrey', 'Nuevo León', '65000', '', '', '', '', '', '', 'M05', 'negro', 'msi', '', '5562', '', '48260', '2026-04-06 17:39', 'pi_3TJH7nDPx1FQbvVS0RM32zbc'),
('23', '1775502429', '', 'David', '5514516605', 'dm@voltika.mx', '', '', '', 'Ciudad de México', 'Distrito Federal', '11510', '', '', '', '', '', '', 'M05', 'gris', 'enganche', '', '12065', '', '12065', '2026-04-06 19:07', 'pi_3TJIUKDPx1FQbvVS0jUk7bcZ');

-- --------------------------------------------------------
-- Table: `facturacion`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `facturacion`;
CREATE TABLE `facturacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `modelo` varchar(200) DEFAULT NULL,
  `metodo` varchar(50) DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `rfc` varchar(20) DEFAULT NULL,
  `razon` varchar(200) DEFAULT NULL,
  `uso_cfdi` varchar(10) DEFAULT NULL,
  `calle` varchar(300) DEFAULT NULL,
  `cp` varchar(10) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `estado` varchar(100) DEFAULT NULL,
  `freg` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

INSERT INTO `facturacion` VALUES
('1', 'Juan Perez', 'test@example.com', 'M05', 'contado', '48260.00', 'PELJ900115ABC', 'Juan Perez Lopez SA', 'G03', 'Calle 123', '44100', 'Guadalajara', 'Jalisco', '2026-03-05 16:43:12'),
('2', 'Test', 'test@test.com', 'VK1', '', '109900.00', 'XAXX010101000', 'JUAN PEREZ', 'G03', 'Reforma 123', '06600', 'CDMX', 'CDMX', '2026-03-05 19:40:58'),
('3', 'Indra test', 'aupwork00@gmail.com', 'M05', '', '50060.00', 'XAXX009987978', 'Indra test', 'D06', '100 East Main Street', '06600', 'Ciudad de Mexico', 'CDMX', '2026-03-06 00:33:51'),
('4', 'Indra test', 'aupwork00@gmail.com', 'M05', '', '50060.00', 'XAXX009987978', 'Indra test', 'G03', '100 East Main Street', '22222', 'Tijuana', 'Baja California', '2026-03-06 19:00:33'),
('5', 'Indra test', 'aupwork00@gmail.com', 'M05', '', '50060.00', 'XAXX009987978', 'Indra test', 'G03', '100 East Main Street', '22222', 'Tijuana', 'Baja California', '2026-03-08 06:20:23'),
('6', 'Bddbdbdbdjd', 'dm@meusnier.com.mx', 'M05', '', '50060.00', 'GME931206MI4', 'Bddbdbdbdjd', 'G03', 'Fffccv', '54900', 'Tultitlán de Mariano Escobedo', 'México', '2026-03-20 05:27:57'),
('7', 'D d de d', 'dm@meusnier.com.mx', 'M05', '', '50060.00', 'GME931206MI4', 'D d de d', 'G03', 'Xbox bd', '54900', 'Tultitlán de Mariano Escobedo', 'México', '2026-03-20 05:35:22'),
('8', 'Jddbbdjddj', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', 'Jddbbdjddj', 'G03', 'Hhjjjjii', '54900', 'Tultitlán de Mariano Escobedo', 'México', '2026-03-20 05:48:02'),
('9', 'Juan', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', 'Juan', 'G03', 'Bzbbzz', '35000', 'Gómez Palacio', 'Durango', '2026-03-20 05:58:54'),
('10', 'Bdbdbdbdbd', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', 'Bdbdbdbdbd', 'G03', 'Vzvbzzb', '54900', 'Tultitlán de Mariano Escobedo', 'México', '2026-03-21 08:00:00'),
('11', 'Hebddbdb', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', 'Hebddbdb', 'G03', 'Bdbbdbdb', '35000', 'Gómez Palacio', 'Durango', '2026-03-21 14:55:10'),
('12', 'Bsbsbdbsb', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', 'Bsbsbdbsb', 'G03', 'Babbzbs', '64000', 'Monterrey', 'Nuevo León', '2026-03-22 14:21:46'),
('13', 'Herhehdh', 'dm@meusnier.com.mx', 'M05', '', '50060.00', 'BDBDBBDBDB', 'Herhehdh', 'G03', 'Bsbbdbdb', '94000', 'Boca del Rio', 'Veracruz de Ignacio de la Llave', '2026-03-22 15:19:36'),
('14', 'Bdbdbd', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', '', 'G03', '', '', '', '', '2026-03-23 06:40:54'),
('15', 'Prueba González', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', '', 'G03', '', '', '', '', '2026-03-23 06:46:34'),
('16', 'Jacinto', 'dm@meusnier.com.mx', 'M05', '', '50060.00', '', '', 'G03', '', '', '', '', '2026-03-23 07:01:56'),
('17', '', '', 'M05', '', '0.00', '', '', 'G03', '', '', '', '', '2026-03-24 12:52:53'),
('18', 'Juan Pérez', 'dm@meusnier.com.mx', 'M05', '', '50660.00', '', '', 'G03', '', '', '', '', '2026-03-26 01:45:34'),
('19', '', '', 'M05', '', '0.00', '', '', 'G03', '', '', '', '', '2026-03-26 22:18:40'),
('20', '', '', 'M05', '', '0.00', '', '', 'G03', '', '', '', '', '2026-03-26 23:50:17'),
('21', 'Juan Pérez', 'dm@meusnier.com.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-27 00:36:01'),
('22', 'Ccxcccvv', 'dm@meusnier.com.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-28 07:04:17'),
('23', '', '', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-28 13:35:37'),
('24', '', '', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-28 13:41:41'),
('25', 'Héctor Bdbdbd jdjddj', 'dm@meusnier.com.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-28 18:48:45'),
('26', 'Vdbdbdbdb bdbdbdbdd', 'dm@meusnier.com.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-28 18:58:04'),
('27', 'Jdjdjdndb bdbdbddbbd', 'dm@mtechmexico.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-29 19:00:34'),
('28', 'Juan Pérez', 'dm@mtechmexico.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-29 19:04:40'),
('29', 'Juan Pérez', 'dm@mtechmexico.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-29 19:17:20'),
('30', 'Bdbdbbddbd', 'dm@mtechgears.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-30 01:47:16'),
('31', 'Juan Pérez', 'dm@mtechmexico.mx', 'Ukko S+', '', '89900.00', '', '', 'G03', '', '', '', '', '2026-03-30 15:13:00'),
('32', 'Juan Pérez', 'dm@voltika.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-03-31 14:35:04'),
('33', 'John Doe', 'Dm@voltika.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-04-05 18:34:21'),
('34', 'Raúl González Pérez', 'dm@voltika.mx', 'M05', '', '48260.00', '', '', 'G03', '', '', '', '', '2026-04-06 17:46:42');

-- Table `consultas_buro` not found — skipped

-- Table `preaprobaciones` not found — skipped

-- Table `verificaciones_identidad` not found — skipped

SET FOREIGN_KEY_CHECKS=1;
