$(document).ready(function () {
    const scrollContainer = $('#scrollContainer');

    $('.right-panel button').click(function () {
      const targetId = $(this).data('target');
      const target = $('#' + targetId);

      if (target.length) {
        scrollContainer.stop(true); // Detener cualquier animación actual

        const targetOffset = target.position().top + scrollContainer.scrollTop();

        scrollContainer.animate({
          scrollTop: targetOffset
        }, 800);
      }
    });


    const container = $('#horizontalContent');
    const slideWidth = container.width(); // 100% del contenedor visible

    $('#scroll-left').click(function () {
      container.animate({ scrollLeft: container.scrollLeft() - slideWidth }, 500);
    });

    $('#scroll-right').click(function () {
      container.animate({ scrollLeft: container.scrollLeft() + slideWidth }, 500);
    });

    // Actualiza el tamaño del slide dinámicamente al redimensionar
    $(window).on('resize', function () {
      // Para asegurarte que el slideWidth esté actualizado
    });

    const imagenOriginal = $('#cont_img').attr('src');
    let timeoutID;
    var modelo = $(this).data('modelo');
    $("#btn_color").attr('href','');
    $("#btn_accesorios").attr('href','');

    $("#color_m03").hide();
    $("#color_m05").hide();
    $("#color_mc10").hide();
    $("#color_mino").hide();
    $("#color_pesgo").hide();
    $("#color_ukko").hide();

    $("#div_tx").hide();

    $("#galeria").hide();

    /*$('.modelo').on('mouseenter', function() {
        clearTimeout(timeoutID);
        var nuevaImagen = $(this).data('img');
        $('#cont_img').fadeOut('fast', function() {
            $(this).attr('src', nuevaImagen).fadeIn('slow');
        });
    });*/

    /*$('.modelo').on('mouseleave', function() {
        timeoutID = setTimeout(function() {
        $('#cont_img').stop(true, true).fadeOut('slow', function() {
            $(this).attr('src', imagenOriginal).fadeIn('slow');
        });
        }, 1000);
    });*/


    //----------------- VARIABLES GENERALES --------------------
    var modelo_n;
    var color;
    var tpago;
    var tenvio;
    var penvio;

    $("#envio1_val").hide();

    function resumen_compra(){
        $(".res_modelo").html(modelo_n);
        $(".res_color").html(color);
        $(".res_tpago").html(tpago);
        $(".res_tenvio").html(tenvio);
        $(".res_penvio").html(penvio);
        $(".res_stotal").html("$"+total_pc.toLocaleString());
        $(".res_total").html("$"+total.toLocaleString());
    }

    $("#btn_modelo").click(function(){
        if(modelo=="m03"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/m03/model.png').fadeIn('slow');
            });
        }
        else if(modelo=="m05"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/m05/model.png').fadeIn('slow');
            });
        }
        else if(modelo=="mc10"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/mc10/model.png').fadeIn('slow');
            });
        }
        else if(modelo=="pesgo"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/pesgo/model.png').fadeIn('slow');
            });
        }
        else if(modelo=="mino"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/mino/model.png').fadeIn('slow');
            });
        }
        else if(modelo=="ukko"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/ukko/model.png').fadeIn('slow');
            });
        }
    });

    $('.modelo').click(function(){
        $("#color").collapse("show");
        $("#galeria").fadeIn("slow");
        $("#color_m03").hide();
        $("#color_m05").hide();
        $("#color_mc10").hide();
        $("#color_mino").hide();
        $("#color_pesgo").hide();
        $("#color_ukko").hide();
        $("#div_tx").fadeIn("slow");
        var nuevaImagen = $(this).data('img');
        modelo = $(this).data('modelo');

        $('#cont_img').fadeOut('fast', function() {
            $(this).attr('src', nuevaImagen).fadeIn('slow');
        })
        
        if(modelo=="m03"){
            modelo_n = "Voltika Tromox M03";
            precio = precio_m03;
            precio_r = precio_m03_r;
            total = precio_m03;
            precio_q = precio_m03_q;
            calcular();
            $("#color_m03").show();
            $("#tx_nombre").html("<img src='img/menu_m03_tx.svg' style='max-width:150px;'>");
            $("#tx_aut").html("90");
            $("#tx_torque").html("100");
            $("#tx_power").html("2.5");
            var img_data1 = "img/configurador/m03/gal1.jpg"
            var img_data2 = "img/configurador/m03/gal2.jpg"
            var img_data3 = "img/configurador/m03/gal3.jpg"
            var img_data4 = "img/configurador/m03/gal4.jpg"
            $("#img1").attr("href",img_data1);
            $("#img1").attr("data-thumbnail",img_data1);
            $("#img_1").attr("src",img_data1);
            $("#img2").attr("href",img_data2);
            $("#img2").attr("data-thumbnail",img_data2);
            $("#img_2").attr("src",img_data2);
            $("#img3").attr("href",img_data3);
            $("#img3").attr("data-thumbnail",img_data3);
            $("#img_3").attr("src",img_data3);
            $("#img4").attr("href",img_data4);
            $("#img4").attr("data-thumbnail",img_data4);
            $("#img_4").attr("src",img_data4);
        }
        if(modelo=="m05"){
            modelo_n = "Voltika Tromox M05";
            precio = precio_m05;
            precio_r = precio_m05_r;
            total = precio_m05;
            precio_q = precio_m05_q;
            calcular();
            $("#color_m05").show();
            $("#tx_nombre").html("<img src='img/menu_m05_tx.svg' style='max-width:150px;'>");
            $("#tx_aut").html("95");
            $("#tx_torque").html("130");
            $("#tx_power").html("3");
            var img_data1 = "img/configurador/m05/gal1.jpg"
            var img_data2 = "img/configurador/m05/gal2.jpg"
            var img_data3 = "img/configurador/m05/gal3.jpg"
            var img_data4 = "img/configurador/m05/gal4.jpg"
            $("#img1").attr("href",img_data1);
            $("#img1").attr("data-thumbnail",img_data1);
            $("#img_1").attr("src",img_data1);
            $("#img2").attr("href",img_data2);
            $("#img2").attr("data-thumbnail",img_data2);
            $("#img_2").attr("src",img_data2);
            $("#img3").attr("href",img_data3);
            $("#img3").attr("data-thumbnail",img_data3);
            $("#img_3").attr("src",img_data3);
            $("#img4").attr("href",img_data4);
            $("#img4").attr("data-thumbnail",img_data4);
            $("#img_4").attr("src",img_data4);
        }
        if(modelo=="mc10"){
            modelo_n = "Voltika Tromox MC10";
            precio = precio_mc10;
            precio_r = precio_mc10_r;
            total = precio_mc10;
            precio_q = precio_mc10_q;
            calcular();
            $("#color_mc10").show();
            $("#tx_nombre").html("<img src='img/menu_mc10_tx.svg' style='max-width:150px;'>");
            $("#tx_aut").html("90");
            $("#tx_torque").html("320");
            $("#tx_power").html("11");
            var img_data1 = "img/configurador/mc10/gal1.jpg"
            var img_data2 = "img/configurador/mc10/gal2.jpg"
            var img_data3 = "img/configurador/mc10/gal3.jpg"
            var img_data4 = "img/configurador/mc10/gal4.jpg"
            $("#img1").attr("href",img_data1);
            $("#img1").attr("data-thumbnail",img_data1);
            $("#img_1").attr("src",img_data1);
            $("#img2").attr("href",img_data2);
            $("#img2").attr("data-thumbnail",img_data2);
            $("#img_2").attr("src",img_data2);
            $("#img3").attr("href",img_data3);
            $("#img3").attr("data-thumbnail",img_data3);
            $("#img_3").attr("src",img_data3);
            $("#img4").attr("href",img_data4);
            $("#img4").attr("data-thumbnail",img_data4);
            $("#img_4").attr("src",img_data4);
        }
        if(modelo=="pesgo"){
            modelo_n = "Voltika Tromox Pesgo";
            precio = precio_pesgo;
            precio_r = precio_pesgo_r;
            total = precio_pesgo;
            precio_q = precio_pesgo_q;
            calcular();
            $("#color_pesgo").show();
            $("#tx_nombre").html("<img src='img/menu_pesgo_tx.svg' style='max-width:150px;'>");
            $("#tx_aut").html("80");
            $("#tx_torque").html("100");
            $("#tx_power").html("2.5");
            var img_data1 = "img/configurador/pesgo/gal1.jpg"
            var img_data2 = "img/configurador/pesgo/gal2.jpg"
            var img_data3 = "img/configurador/pesgo/gal3.jpg"
            var img_data4 = "img/configurador/pesgo/gal4.jpg"
            $("#img1").attr("href",img_data1);
            $("#img1").attr("data-thumbnail",img_data1);
            $("#img_1").attr("src",img_data1);
            $("#img2").attr("href",img_data2);
            $("#img2").attr("data-thumbnail",img_data2);
            $("#img_2").attr("src",img_data2);
            $("#img3").attr("href",img_data3);
            $("#img3").attr("data-thumbnail",img_data3);
            $("#img_3").attr("src",img_data3);
            $("#img4").attr("href",img_data4);
            $("#img4").attr("data-thumbnail",img_data4);
            $("#img_4").attr("src",img_data4);
        }
        if(modelo=="mino"){
            modelo_n = "Voltika Tromox Mino B";
            precio = precio_mino;
            precio_r = precio_mino_r;
            total = precio_mino;
            precio_q = precio_mino_q;
            calcular();
            $("#color_mino").show();
            $("#tx_nombre").html("<img src='img/menu_mino_tx.svg' style='max-width:150px;'>");
            $("#tx_aut").html("90");
            $("#tx_torque").html("110");
            $("#tx_power").html("2.5");
            var img_data1 = "img/configurador/mino/gal1.jpg"
            var img_data2 = "img/configurador/mino/gal2.jpg"
            var img_data3 = "img/configurador/mino/gal3.jpg"
            var img_data4 = "img/configurador/mino/gal4.jpg"
            $("#img1").attr("href",img_data1);
            $("#img1").attr("data-thumbnail",img_data1);
            $("#img_1").attr("src",img_data1);
            $("#img2").attr("href",img_data2);
            $("#img2").attr("data-thumbnail",img_data2);
            $("#img_2").attr("src",img_data2);
            $("#img3").attr("href",img_data3);
            $("#img3").attr("data-thumbnail",img_data3);
            $("#img_3").attr("src",img_data3);
            $("#img4").attr("href",img_data4);
            $("#img4").attr("data-thumbnail",img_data4);
            $("#img_4").attr("src",img_data4);
        }
        if(modelo=="ukko"){
            modelo_n = "Voltika Tromox Ukko S+";
            precio = precio_ukko;
            precio_r = precio_ukko_r;
            total = precio_ukko;
            precio_q = precio_ukko_q;
            calcular();
            $("#color_ukko").show();
            $("#tx_nombre").html("<img src='img/menu_ukko_tx.svg' style='max-width:150px;'>");
            $("#tx_aut").html("130");
            $("#tx_torque").html("110");
            $("#tx_power").html("9");
            var img_data1 = "img/configurador/ukko/gal1.jpg"
            var img_data2 = "img/configurador/ukko/gal2.jpg"
            var img_data3 = "img/configurador/ukko/gal3.jpg"
            var img_data4 = "img/configurador/ukko/gal4.jpg"
            $("#img1").attr("href",img_data1);
            $("#img1").attr("data-thumbnail",img_data1);
            $("#img_1").attr("src",img_data1);
            $("#img2").attr("href",img_data2);
            $("#img2").attr("data-thumbnail",img_data2);
            $("#img_2").attr("src",img_data2);
            $("#img3").attr("href",img_data3);
            $("#img3").attr("data-thumbnail",img_data3);
            $("#img_3").attr("src",img_data3);
            $("#img4").attr("href",img_data4);
            $("#img4").attr("data-thumbnail",img_data4);
            $("#img_4").attr("src",img_data4);
        }
         $("#div_right").delay(300).animate({
            scrollTop: 800
        }, 800);
        toastr["info"]("Pasa al paso 2 y elige un color");
        resumen_compra();
    });

    $("#btn_color").click(function(){
        if(modelo=="m03"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/m03/black_side.png').fadeIn('slow');
            });
        }
        else if(modelo=="m05"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/m05/black_side.png').fadeIn('slow');
            });
        }
        else if(modelo=="mc10"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/mc10/black_side.png').fadeIn('slow');
            });
        }
        else if(modelo=="pesgo"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/pesgo/black_side.png').fadeIn('slow');
            });
        }
        else if(modelo=="mino"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/mino/green_side.png').fadeIn('slow');
            });
        }
        else if(modelo=="ukko"){
            $('#cont_img').fadeOut('fast', function() {
                $(this).attr('src', 'img/configurador/ukko/black_side.png').fadeIn('slow');
            });
        }
    });

    $(".color").click(function(){
        $("#pago").collapse("show");
        var nuevaImagen = $(this).data('img');
        color = $(this).data('nombre');
        $('#cont_img').fadeOut('fast', function() {
            $(this).attr('src', nuevaImagen).fadeIn('slow');
        });
        $("#div_right").delay(300).animate({
            scrollTop: 1100
        }, 800);
        toastr["info"]("Pasa al paso 3 y selecciona la forma de pago");
        resumen_compra();
    });

    var precio = 0;
    var precio_r = 0;
    var precio_q = 0;
    var total = 0;
    var total_pc = 0;
    var total_stripe = 0;
    var mes = precio/9;

    var precio_m03 = 39900;
    var precio_m03_r = 44790;
    var precio_m03_q = 0;

    var precio_m05 = 48260;
    var precio_m05_r = 51550;
    var precio_m05_q = 0;

    var precio_mc10 = 109900;
    var precio_mc10_r = 136079;
    var precio_mc10_q = 0;

    var precio_mino = 41820;
    var precio_mino_r = 45100;
    var precio_mino_q = 0;
    
    var precio_pesgo = 36600;
    var precio_pesgo_r = 37420;
    var precio_pesgo_q = 0;

    var precio_ukko = 89900;
    var precio_ukko_r = 105990;
    var precio_ukko_q = 0;

    var msiEnabled = false;

    var precio_envio = 0;

    $("._precio_m03").html(precio_m03.toLocaleString());
    $("._precio_m03_r").html(precio_m03_r.toLocaleString());
    $("._precio_m05").html(precio_m05.toLocaleString());
    $("._precio_m05_r").html(precio_m05_r.toLocaleString());
    $("._precio_mc10").html(precio_mc10.toLocaleString());
    $("._precio_mc10_r").html(precio_mc10_r.toLocaleString());
    $("._precio_mino").html(precio_mino.toLocaleString());
    $("._precio_mino_r").html(precio_mino_r.toLocaleString());
    $("._precio_pesgo").html(precio_pesgo.toLocaleString());
    $("._precio_pesgo_r").html(precio_pesgo_r.toLocaleString());
    $("._precio_ukko").html(precio_ukko.toLocaleString());
    $("._precio_ukko_r").html(precio_ukko_r.toLocaleString());

    function calcular(){
        mes = precio/9;
        mes_ = mes.toFixed(0);
        $("._precio_total").html(total.toLocaleString());
        $("._precio").html(precio.toLocaleString());
        $("._precio_r").html(precio_r.toLocaleString());
        $("._mes").html(mes_.toLocaleString());
        $("._precio_q").html(precio_q.toLocaleString());
    }

    calcular();

    $("#btn_pago1").click(function(){
        mes = precio/9;
        mes_ = mes.toFixed(0);
        tpago = "Hasta 9 MSI de $"+mes_.toLocaleString()+" MXN con tarjeta de crédito";
        $("#envio").collapse("show");
        total = precio;
        total_stripe = total*100;
        total_pc = total;
        msiEnabled = true;
        calcular();
        $("#div_right").delay(300).animate({
            scrollTop: 1500
        }, 800);
        toastr["info"]("Escoge el tipo de entrega de tu Voltika en paso 4");
        resumen_compra();
        return false;
    });

    $("#btn_pago2").click(function(){
        tpago = "Un solo pago con descuento";
        $("#envio").collapse("show");
        total = precio;
        total_stripe = total*100;
        total_pc = total;
        msiEnabled = false;
        calcular();
        $("#div_right").delay(300).animate({
            scrollTop: 1500
        }, 800);
        toastr["info"]("Escoge el tipo de entrega de tu Voltika en paso 4");
        resumen_compra();
        return false;
    });

    $("#btn_envio1").click(function(){
        $("#envio1_val").fadeIn();
        /*tenvio = "Envío a domicilio";
        penvio = "Sin costo";
        $("#resumen").collapse("show");
        total = total_pc + precio_envio;
        total_stripe = total*100;
        calcular();
        $("#div_right").delay(300).animate({
            scrollTop: 1600
        }, 800);
        toastr["info"]("Pasa a paso 5 para confirmar tu compra");
        $("#envio_div").show();
        $("#e_nombre").attr("required","required");
        $("#e_telefono").attr("required","required");
        $("#e_direccion").attr("required","required");
        $("#e_ciudad").attr("required","required");
        $("#e_estado").attr("required","required");
        $("#e_cp").attr("required","required");
        resumen_compra();*/
        $("#div_right").delay(300).animate({
            scrollTop: 1600
        }, 800);
        return false;
    });

    $("#cp_val_btn").click(function(){
        var cp_val = $("#cp_val").val();
        let cp = $("#cp_val").val().trim();
        // Validar que tenga exactamente 5 dígitos numéricos
        if(/^\d{5}$/.test(cp)){
            $.ajax({
                url : "php/cp.php",
                type : "post",
                data : {"cp":cp_val},
                dataType : "json",
                success : function(data){
                    var status = data[0].status;
                    var colonia = data[0].colonia;
                    var ciudad = data[0].ciudad;
                    var estado = data[0].estado;
                    if(status=="ok"){
                        $("#colonia_val").html(colonia);
                        $("#ciudad_val").val(ciudad);
                        $("#estado_val").val(estado);

                        if(estado=="Aguascalientes"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2200;
                                $("#precio_envio").html("$2,200");
                                penvio = "$2,200";
                            }
                            else
                            {
                                precio_envio = 1800;
                                $("#precio_envio").html("$1,800");
                                penvio = "$1,800";
                            }
                        }
                        else if(estado=="Baja California"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 6550;
                                $("#precio_envio").html("$6,550");
                                penvio = "$6,550";
                            }
                            else
                            {
                                precio_envio = 5,550;
                                $("#precio_envio").html("$5,550");
                                penvio = "$5,550";
                            }
                        }
                        else if(estado=="Baja California Sur"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 7850;
                                $("#precio_envio").html("$7,850");
                                penvio = "$7,850";
                            }
                            else
                            {
                                precio_envio = 6000;
                                $("#precio_envio").html("$6,000");
                                penvio = "$6,000";
                            }
                        }
                        else if(estado=="Campeche"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 5550;
                                $("#precio_envio").html("$5,550");
                                penvio = "$5,500";
                            }
                            else
                            {
                                precio_envio = 3950;
                                $("#precio_envio").html("$3,950");
                                penvio = "$3,950";
                            }
                        }
                        else if(estado=="Chiapas"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 5550;
                                $("#precio_envio").html("$5,550");
                                penvio = "$5,550";
                            }
                            else
                            {
                                precio_envio = 3950;
                                $("#precio_envio").html("$3,950");
                                penvio = "$3,950";
                            }
                        }
                        else if(estado=="Chihuahua"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4850;
                                $("#precio_envio").html("$4,850");
                                penvio = "$4,850";
                            }
                            else
                            {
                                precio_envio = 3950;
                                $("#precio_envio").html("$3,950");
                                penvio = "$3,950";
                            }
                        }
                        else if(estado=="Ciudad de México"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 1500;
                                $("#precio_envio").html("$1,500");
                                penvio = "$1,500";
                            }
                            else
                            {
                                precio_envio = 1500;
                                $("#precio_envio").html("$1,500");
                                penvio = "$1,500";
                            }
                        }
                        else if(estado=="Coahuila de Zaragoza"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4850;
                                $("#precio_envio").html("$4,850");
                                penvio = "$4,850";
                            }
                            else
                            {
                                precio_envio = 3950;
                                $("#precio_envio").html("$3,950");
                                penvio = "$3,950";
                            }
                        }
                        else if(estado=="Colima"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4850;
                                $("#precio_envio").html("$4,850");
                                penvio = "$4,850";
                            }
                            else
                            {
                                precio_envio = 3950;
                                $("#precio_envio").html("$3,950");
                                penvio = "$3,950";
                            }
                        }
                        else if(estado=="Durango"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4850;
                                $("#precio_envio").html("$4,850");
                                penvio = "$4,850";
                            }
                            else
                            {
                                precio_envio = 2800;
                                $("#precio_envio").html("$2,800");
                                penvio = "$2,800";
                            }
                        }
                        else if(estado=="Guanajuato"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2850;
                                $("#precio_envio").html("$2,850");
                                penvio = "$2,850";
                            }
                            else
                            {
                                precio_envio = 2400;
                                $("#precio_envio").html("$2,400");
                                penvio = "$2,400";
                            }
                        }
                        else if(estado=="Guerrero"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 3450;
                                $("#precio_envio").html("$3,450");
                                penvio = "$3,450";
                            }
                            else
                            {
                                precio_envio = 2800;
                                $("#precio_envio").html("$2,800");
                                penvio = "$2,800";
                            }
                        }
                        else if(estado=="Hidalgo"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2200;
                                $("#precio_envio").html("$2,200");
                                penvio = "$2,200";
                            }
                            else
                            {
                                precio_envio = 1800;
                                $("#precio_envio").html("$1,800");
                                penvio = "$1,800";
                            }
                        }
                        else if(estado=="Jalisco"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2850;
                                $("#precio_envio").html("$2,850");
                                penvio = "$2,850";
                            }
                            else
                            {
                                precio_envio = 2400;
                                $("#precio_envio").html("$2,400");
                                penvio = "$2,400";
                            }
                        }
                        else if(estado=="México"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 0;
                                $("#precio_envio").html("$0");
                                penvio = "$0";
                            }
                            else
                            {
                                precio_envio = 0;
                                $("#precio_envio").html("$0");
                                penvio = "$0";
                            }
                        }
                        else if(estado=="Michoacán de Ocampo"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 3850;
                                $("#precio_envio").html("$3,850");
                                penvio = "$3,850";
                            }
                            else
                            {
                                precio_envio = 2800;
                                $("#precio_envio").html("$2,800");
                                penvio = "$2,800";
                            }
                        }
                        else if(estado=="Morelos"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2200;
                                $("#precio_envio").html("$2,200");
                                penvio = "$2,200";
                            }
                            else
                            {
                                precio_envio = 1800;
                                $("#precio_envio").html("$1,800");
                                penvio = "$1,800";
                            }
                        }
                        else if(estado=="Nayarit"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 3850;
                                $("#precio_envio").html("$3,850");
                                penvio = "$3,850";
                            }
                            else
                            {
                                precio_envio = 2800;
                                $("#precio_envio").html("$2,800");
                                penvio = "$2,800";
                            }
                        }
                        else if(estado=="Nuevo León"){
                           if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2800;
                                $("#precio_envio").html("$2,800");
                                penvio = "$2,800";
                            }
                            else
                            {
                                precio_envio = 1800;
                                $("#precio_envio").html("$1,800");
                                penvio = "$1,800";
                            }
                        }
                        else if(estado=="Oaxaca"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 5500;
                                $("#precio_envio").html("$5,500");
                                penvio = "$5,500";
                            }
                            else
                            {
                                precio_envio = 4800;
                                $("#precio_envio").html("$4,800");
                                penvio = "$4,800";
                            }
                        }
                        else if(estado=="Puebla"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2200;
                                $("#precio_envio").html("$2,200");
                                penvio = "$2,200";
                            }
                            else
                            {
                                precio_envio = 1800;
                                $("#precio_envio").html("$1,800");
                                penvio = "$1,800";
                            }
                        }
                        else if(estado=="Querétaro"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2200;
                                $("#precio_envio").html("$2,200");
                                penvio = "$2,200";
                            }
                            else
                            {
                                precio_envio = 1800;
                                $("#precio_envio").html("$1,800");
                                penvio = "$1,800";
                            }
                        }
                        else if(estado=="Quintana Roo"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 5500;
                                $("#precio_envio").html("$5,500");
                                penvio = "$5,500";
                            }
                            else
                            {
                                precio_envio = 4800;
                                $("#precio_envio").html("$4,800");
                                penvio = "$4,800";
                            }
                        }
                        else if(estado=="San Luis Potosí"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2850;
                                $("#precio_envio").html("$2,850");
                                penvio = "$2,850";
                            }
                            else
                            {
                                precio_envio = 2400;
                                $("#precio_envio").html("$2,400");
                                penvio = "$2,400";
                            }
                        }
                        else if(estado=="Sinaloa"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 5500;
                                $("#precio_envio").html("$5,500");
                                penvio = "$5,500";
                            }
                            else
                            {
                                precio_envio = 4800;
                                $("#precio_envio").html("$4,800");
                                penvio = "$4,800";
                            }
                        }
                        else if(estado=="Sonora"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 5500;
                                $("#precio_envio").html("$5,500");
                                penvio = "$5,500";
                            }
                            else
                            {
                                precio_envio = 4800;
                                $("#precio_envio").html("$4,800");
                                penvio = "$4,800";
                            }
                        }
                        else if(estado=="Tabasco"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4550;
                                $("#precio_envio").html("$4,550");
                                penvio = "$4,550";
                            }
                            else
                            {
                                precio_envio = 3850;
                                $("#precio_envio").html("$3,850");
                                penvio = "$3,850";
                            }
                        }
                        else if(estado=="Tamaulipas"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4200;
                                $("#precio_envio").html("$4,200");
                                penvio = "$4,200";
                            }
                            else
                            {
                                precio_envio = 3850;
                                $("#precio_envio").html("$3,850");
                                penvio = "$3,850";
                            }
                        }
                        else if(estado=="Tlaxcala"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 2200;
                                $("#precio_envio").html("$2,200");
                                penvio = "$2,200";
                            }
                            else
                            {
                                precio_envio = 1800;
                                $("#precio_envio").html("$1,800");
                                penvio = "$1,800";
                            }
                        }
                        else if(estado=="Veracruz de Ignacio de la Llave"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4200;
                                $("#precio_envio").html("$4,200");
                                penvio = "$4,200";
                            }
                            else
                            {
                                precio_envio = 3800;
                                $("#precio_envio").html("$3,800");
                                penvio = "$3,800";
                            }
                        }
                        else if(estado=="Yucatán"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 5500;
                                $("#precio_envio").html("$5,500");
                                penvio = "$5,500";
                            }
                            else
                            {
                                precio_envio = 4800;
                                $("#precio_envio").html("$4,800");
                                penvio = "$4,800";
                            }
                        }
                        else if(estado=="Zacatecas"){
                            if(modelo_n=="Voltika Tromox MC10"){
                                precio_envio = 4200;
                                $("#precio_envio").html("$4,200");
                                penvio = "$4,200";
                            }
                            else
                            {
                                precio_envio = 2400;
                                $("#precio_envio").html("$2,400");
                                penvio = "$2,400";
                            }
                        }
                        tenvio = "Envío a domicilio";
                        $("#resumen").collapse("show");
                        total = total_pc + precio_envio;
                        total_stripe = total*100;
                        calcular();
                        $("#div_right").delay(300).animate({
                            scrollTop: 1800
                        }, 800);
                        toastr["info"]("Pasa a paso 5 para confirmar tu compra");
                        $("#envio_div").show();
                        $("#e_nombre").attr("required","required");
                        $("#e_telefono").attr("required","required");
                        $("#e_direccion").attr("required","required");
                        $("#e_ciudad").attr("required","required");
                        $("#e_estado").attr("required","required");
                        $("#e_cp").attr("required","required");
                        resumen_compra();
                        $("#e_colonia").val(colonia);
                        $("#e_ciudad").val(ciudad);
                        $("#e_estado").val(estado);
                        $("#e_cp").val(cp_val);
                    }
                    if(status=="error"){
                        toastr["error"]("Ocurrió un error, intenta nuevamente");
                    }
                },
                error : function(data){
                    toastr["error"]("No pudimos conectarnos al servidor, intenta nuevamente");
                }
            });
        } 
        else
        {
            toastr["error"]("Ingresa un código postal válido");
        }
    });




    $("#btn_envio2").click(function(){
        $("#envio1_val").fadeOut();
        tenvio = "Recoger en Santa Fe";
        penvio = "Sin costo";
        $("#resumen").collapse("show");
        total = total_pc;
        total_stripe = total*100;
        calcular();
        $("#div_right").delay(300).animate({
            scrollTop: 1600
        }, 800);
        toastr["info"]("Pasa a paso 5 para confirmar tu compra");
        $("#envio_div").hide();
        $("#e_nombre").attr("required",false);
        $("#e_telefono").attr("required",false);
        $("#e_direccion").attr("required",false);
        $("#e_ciudad").attr("required",false);
        $("#e_estado").attr("required",false);
        $("#e_cp").attr("required",false);
        resumen_compra();
        return false;
    });

    $("#btn_resumen_ok").click(function(){
        $("#modelo").collapse("hide");
        $("#color").collapse("hide");
        $("#pago").collapse("hide");
        $("#envio").collapse("hide");
        $(this).hide();
        $("#datos").collapse("show");

        $("#i_precio").val(total_pc);
        $("#i_penvio").val(precio_envio);
        $("#i_total").val(total);
        $("#i_tenvio").val(tenvio);
        $("#i_modelo").val(modelo_n);
        $("#i_color").val(color);

        initialize();
        return false;
    });


    toastr.options = {
        "closeButton": false,
        "debug": false,
        "newestOnTop": false,
        "progressBar": false,
        "positionClass": "toast-bottom-left",
        "preventDuplicates": false,
        "onclick": null,
        "showDuration": 100,
        "hideDuration": 500,
        "timeOut": 1000,
        "extendedTimeOut": 1000,
        "showEasing": "swing",
        "hideEasing": "linear",
        "showMethod": "fadeIn",
        "hideMethod": "fadeOut"
    }

    //---------------- STRIPE -----------------

    //let stripe = Stripe("pk_test_51Rr5XCDPx1FQbvVSr8odW16SQzUgPoyMJroHp5emN9PttKU4oHs0jAOsBpxM50ISn4xmUzemZomXX6IuIrQN8FRB00NM2T7eGp");//------PRUEBA
    let stripe = Stripe("pk_live_51QpalADzBRkc6ufKXRQ98otDT6YUK8HYkjiRtIXcahQxyd5v9YFUU0K5aGN1XZL8ubQj8MyPBIKlu00UtpLRmPpR00qGp7qDwg");//-----PRODUCTIVO
    let elements;

    const baseAmount = 500000;

    function getAdjustedAmount() {
    return $('#msi-check').is(':checked') ? Math.round(baseAmount * 1.10) : baseAmount;
    }

    function updateAmountDisplay() {
    const amount = getAdjustedAmount();
    $('#amount-display').text(`$${(amount / 100).toFixed(2)}`);
    }

    $(document).ready(function () {
        initialize();

        $('#method').on('change', function () {
            const method = $(this).val();
            $('#msi-container').toggle(method === 'card');
            $('#msi-check').prop('checked', false);
            updateAmountDisplay();
            initialize();
        });

        /*$('#msi-check').on('change', function () {
            updateAmountDisplay();
            initialize();
        });*/

        $('#payment-form').on('submit', handleSubmit);
        });

        async function initialize() {
            const customer = {
                nombre: $('#nombre').val(),
                email: $('#email').val(),
                telefono: $('#telefono').val(),
                direccion: {
                line1: $('#direccion').val(),
                line2: $('#direccion').val(),
                ciudad: $('#ciudad').val(),
                estado: $('#estado').val(),
                codigo_postal: $('#codigo_postal').val(),
                pais: $('#pais').val()
                }
            };

            //const method = $('#method').val();
            const method = "card";
            //const msiEnabled = $('#msi-check').is(':checked');
            const amount = getAdjustedAmount();

            const body = {
                amount: total_stripe,
                method: method,
                installments: msiEnabled,
                customer: customer
            };

            const response = await fetch('php/stripe_create-payment-intent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });

            const { clientSecret } = await response.json();
            elements = stripe.elements({ clientSecret });
            const paymentElement = elements.create('payment');
            $('#payment-element').html('');
            paymentElement.mount('#payment-element');
        }

        async function handleSubmit(e) {
        e.preventDefault();
        $('#submit').prop('disabled', true);

        const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: { return_url: window.location.href },
            redirect: "if_required"
        });

        if (error) {
            $('#payment-message').text(error.message);
            $('#submit').prop('disabled', false);
        } else {
            finalizar_compra();
            $('#submit').prop('disabled', true);

            
        }
    }

    function finalizar_compra(){
        var datos = document.getElementById("payment-form");
        var formD = new FormData(datos);
        $.ajax({
            url : "php/finalizar_compra.php",
            type : "post",
            data : formD,
            cache : false,
            processData: false,  
            contentType: false, 
            dataType : "json",
            success : function(data){
                var status = data[0].status;
                var f_pedido = data[0].pedido;
                var f_freg = data[0].freg;
                var f_email = data[0].email;
                var f_tpago = data[0].tpago;
                var f_total = data[0].total;
                var f_modelo = data[0].modelo;
                var f_color = data[0].color;
                var f_tpago = data[0].tpago;
                var f_tenvio = data[0].tenvio;
                if(status=="ok"){
                    $(".f_pedido").html(f_pedido);
                    $(".f_freg").html(f_freg);
                    $(".f_email").html(f_email);
                    $(".f_tpago").html(f_tpago);
                    $(".f_total").html(f_total);
                    $(".f_modelo").html(f_modelo);
                    $(".f_color").html(f_color);
                    $(".f_tpago").html(f_tpago);
                    $(".f_tenvio").html(f_tenvio);
                    toastr["success"]("Pago registrado exitosamente");
                    $("#modal_pago").modal("show");
                }
                if(status=="error"){
                    toastr["error"]("Ocurrió un error, intenta nuevamente");
                }
            },
            error : function(data){
                toastr["error"]("No tenemos comunicación con el servidor");
            }

        });
    }

    $('#btn-imprimir').on('click', function(){
        // Obtiene el contenido del modal
        let contenido = $('#modal_pago .modal-body').html();

        // Abre una ventana temporal para impresión
        let ventanaImpresion = window.open('', '', 'height=600,width=800');
        ventanaImpresion.document.write('<html><head><title>Compra en www.voltika.mx</title>');
        ventanaImpresion.document.write('</head><body>');
        ventanaImpresion.document.write(contenido);
        ventanaImpresion.document.write('</body></html>');
        ventanaImpresion.document.close();
        ventanaImpresion.print();
        return false;
    });

    //$("#modal_pago").modal("show");

});


