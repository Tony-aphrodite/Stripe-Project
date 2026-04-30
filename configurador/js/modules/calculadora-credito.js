/* ==========================================================================
   Voltika - Credit Calculator (Saldos Insolutos / Declining Balance)
   Parameters verified against client's Excel:
   $109,900 @ 30% enganche = $2,063/week (matches exactly)
   ========================================================================== */

var VkCalculadora = {

    /**
     * Calculate credit terms
     * @param {number} precioContado - Cash price of the motorcycle
     * @param {number} enganchePorcentaje - Down payment percentage (0.30 = 30%)
     * @param {number} plazoMeses - Term in months (default 12)
     * @returns {object} Full credit calculation results
     */
    calcular: function(precioContado, enganchePorcentaje, plazoMeses) {
        var config = VOLTIKA_PRODUCTOS.config.credito;
        plazoMeses = plazoMeses || config.plazoDefaultMeses;
        enganchePorcentaje = enganchePorcentaje || config.engancheMinimo;

        // Step 1: Down payment and financed amount
        var enganche = Math.round(precioContado * enganchePorcentaje);
        var montoFinanciado = precioContado - enganche;

        // Step 2: Period rate
        var tasaPeriodoSinIVA = config.tasaAnual / config.pagosPorAno;
        var tasaPeriodoConIVA = tasaPeriodoSinIVA * (1 + config.iva);

        // Step 3: Number of payments
        var numeroPagos = Math.round(plazoMeses * (config.pagosPorAno / 12));

        // Step 4: Fixed payment (PMT formula with declining balance)
        // PMT = P * r / (1 - (1 + r)^(-n))
        var r = tasaPeriodoConIVA;
        var n = numeroPagos;
        var P = montoFinanciado;

        var pagoSemanal;
        if (r === 0) {
            pagoSemanal = P / n;
        } else {
            pagoSemanal = P * r / (1 - Math.pow(1 + r, -n));
        }

        // Step 5: Calculate totals
        var totalPagos = pagoSemanal * n;
        var totalConEnganche = totalPagos + enganche;

        // Step 6: Generate amortization summary
        var interesTotal = 0;
        var ivaTotal = 0;
        var saldo = montoFinanciado;

        for (var i = 0; i < n; i++) {
            var interesPeriodo = saldo * tasaPeriodoSinIVA;
            var ivaPeriodo = interesPeriodo * config.iva;
            var capital = pagoSemanal - interesPeriodo - ivaPeriodo;

            interesTotal += interesPeriodo;
            ivaTotal += ivaPeriodo;
            saldo -= capital;
        }

        return {
            precioContado: precioContado,
            enganchePorcentaje: enganchePorcentaje,
            enganche: enganche,
            montoFinanciado: montoFinanciado,
            plazoMeses: plazoMeses,
            numeroPagos: numeroPagos,
            pagoSemanal: Math.round(pagoSemanal),
            totalPagos: Math.round(totalPagos),
            totalConEnganche: Math.round(totalConEnganche),
            interesTotal: Math.round(interesTotal * 100) / 100,
            ivaTotal: Math.round(ivaTotal * 100) / 100,
            tasaAnual: config.tasaAnual * 100,
            tasaPeriodoSinIVA: Math.round(tasaPeriodoSinIVA * 100000) / 1000,
            tasaPeriodoConIVA: Math.round(tasaPeriodoConIVA * 100000) / 1000,
            frecuencia: 'Semanal'
        };
    },

    /**
     * Generate full amortization table
     * @param {number} precioContado
     * @param {number} enganchePorcentaje
     * @param {number} plazoMeses
     * @returns {Array} Array of period objects
     */
    tablaAmortizacion: function(precioContado, enganchePorcentaje, plazoMeses) {
        var calc = this.calcular(precioContado, enganchePorcentaje, plazoMeses);
        var config = VOLTIKA_PRODUCTOS.config.credito;
        var tasaPeriodoSinIVA = config.tasaAnual / config.pagosPorAno;

        var tabla = [];
        var saldo = calc.montoFinanciado;
        var fechaInicio = new Date();

        for (var i = 0; i < calc.numeroPagos; i++) {
            var fechaPago = new Date(fechaInicio);
            fechaPago.setDate(fechaPago.getDate() + (7 * (i + 1)));

            var interes = saldo * tasaPeriodoSinIVA;
            var iva = interes * config.iva;
            var capital = calc.pagoSemanal - interes - iva;

            // Last payment adjustment
            if (i === calc.numeroPagos - 1) {
                capital = saldo;
            }

            var saldoFinal = Math.max(0, saldo - capital);

            tabla.push({
                periodo: i + 1,
                fecha: fechaPago,
                saldoInicial: Math.round(saldo * 100) / 100,
                pago: calc.pagoSemanal,
                interes: Math.round(interes * 100) / 100,
                iva: Math.round(iva * 100) / 100,
                capital: Math.round(capital * 100) / 100,
                saldoFinal: Math.round(saldoFinal * 100) / 100
            });

            saldo = saldoFinal;
        }

        return tabla;
    }
};
